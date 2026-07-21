<?php
declare(strict_types=1);

/**
 * AERO — USAspending sync (CLI). Per-UEI: pulls a recipient's prime awards
 * (spending_by_award) into usa_award + usa_award_cfda, plus a minimal usa_recipient.
 * USAspending has no key/daily cap but throttles, so we pace and retry on errors.
 * Resumable: skips UEIs whose usa_recipient.last_synced is within --maxage days.
 *
 * Usage:
 *   php sync_usa.php --uei=MW4NM5KU2M81        # one grantee
 *   php sync_usa.php --where=findings          # grantees with findings (default)
 *   php sync_usa.php --where=serious --limit=500
 *   php sync_usa.php --where=all
 *   php sync_usa.php --oldest --limit=700      # staggered nightly chunk (prod cron):
 *                                              #   the N most-overdue recipients, cycling
 *   php sync_usa.php --since=2020-07-01        # widen the retention floor (default 2021-07-01)
 */

$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
require $root . '/lib/Http.php';
require $root . '/lib/RunLog.php';
Env::load(dirname($root, 2) . '/.env');   // above web root (prod; first-set wins)
Env::load(dirname($root) . '/.env');      // repo root (local dev)

function s($v): ?string { if ($v === null) return null; $v = trim((string) $v); return $v === '' ? null : $v; }
function num($v) { return is_numeric($v) ? $v + 0 : null; }
function dd($v): ?string { $v = s($v); if ($v === null) return null; $t = strtotime($v); return $t ? date('Y-m-d', $t) : null; }

/** assistance award-type codes, grouped (USAspending rejects mixing groups in one
 * query), keyed by the usa_award.category each group maps to.
 *
 * Type coverage tracks 2 CFR 200.502(a), which enumerates what counts as "Federal awards
 * expended": (a)(1) grants + cooperative agreements -> 02-05; (a)(3) loan and loan guarantee
 * proceeds -> 07/08; (a)(6) food commodities -> within 06; (a)(8) "the period when insurance is
 * in force" -> 09. 11 rides with 09 as USAspending's "other" group.
 * Type 10 (direct payment with UNRESTRICTED use) is payment to individuals, NOT assistance to
 * the entity being audited, so it is excluded. It was previously pulled here, which silently
 * re-added it every night and would have undone the reseed scope decision.
 * 09/11 were previously dropped "to cut the request rate" — that predated establishing they are
 * statutorily countable (~193k awards / $195.8B that would otherwise never refresh).
 *
 * Loans have a DIFFERENT field mapping: 'Award Amount'/'Total Outlays' are not in
 * the Loan Award mappings (HTTP 400 — this silently broke every UEI's sync, since
 * the loans group always errored and the recipient was never marked done); loans
 * expose 'Loan Value' (face value) and 'Subsidy Cost' instead. 09/11 were verified against the
 * live API on 2026-07-20 and DO accept 'Award Amount'/'Total Outlays' (HTTP 200) — do not assume
 * a new group works, that is exactly how the loans break shipped.
 * 09/11 also return ASST_AGG_ "MULTIPLE RECIPIENTS" rows with a NULL Recipient UEI. The per-award
 * Recipient UEI guard below drops them, which is correct: they are cross-recipient rollups that
 * would double-count against the per-recipient crawl. */
const ASSIST_GROUPS = [
    'grant'          => ['codes' => ['02', '03', '04', '05'], 'money' => ['Award Amount', 'Total Outlays'], 'sort' => 'Award Amount'],
    'direct_payment' => ['codes' => ['06'],                   'money' => ['Award Amount', 'Total Outlays'], 'sort' => 'Award Amount'],
    'loan'           => ['codes' => ['07', '08'],             'money' => ['Loan Value', 'Subsidy Cost'],    'sort' => 'Loan Value'],
    'other'          => ['codes' => ['09', '11'],             'money' => ['Award Amount', 'Total Outlays'], 'sort' => 'Award Amount'],
];

function usa_post(string $path, array $body, int $tries = 5): array
{
    $delay = 2;
    for ($i = 1; $i <= $tries; $i++) {
        try {
            [, , $d] = Http::postJson("https://api.usaspending.gov$path", $body);
            return is_array($d) ? $d : [];
        } catch (Throwable $e) {
            if ($i === $tries) throw $e;
            sleep($delay);
            $delay = min($delay * 2, 30);
        }
    }
    return [];
}

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$pdo    = Db::connect();

// One run at a time (same guard as sync_subawards): a duplicate launch would
// re-crawl the same UEIs concurrently. Auto-releases when the process exits.
if (!(int) $pdo->query("SELECT GET_LOCK('aero_sync_usa', 0)")->fetchColumn()) {
    fwrite(STDERR, "Another sync_usa.php run is already active; exiting.\n");
    exit(1);
}

$up     = new Upserter($pdo);
// The per-ALN breakdown table usa_award_cfda is kept OFF the quota-bound prod DB (~244 MB)
// — the live API degrades obligated-by-ALN gracefully when it's absent. Skip its inserts
// on prod so the nightly never rebuilds it and re-trips the 3 GB quota lockdown.
$SKIP_CFDA = strtolower((string) Env::get('APP_ENV', '')) === 'prod';
$maxage = (int) ($args['maxage'] ?? 30);
// Page cap per award-type group (100 awards/page). Default 0 = NO CAP: pull every page so
// no recipient is truncated (obligation totals must be complete, esp. for states / big
// universities with >1,000 awards). A positive --maxpages can still cap for a quick partial
// pull; hitting that cap sets usa_recipient.sync_truncated so analysis can tell full from top-N.
$maxPages = max(0, (int) ($args['maxpages'] ?? 0));
// Retention floor: only pull awards with activity since this date (USAspending's action_date
// time filter — the closest period-of-performance lever the award search exposes). Default is the
// standard entity-FY2022 start (June-30 FYE). Bounds usa_award growth; pair with
// prune_usa_awards.php for rows already on disk. Widen with --since=YYYY-MM-DD.
$usaSince = (string) ($args['since'] ?? '2021-07-01');

$oldest = isset($args['oldest']);
if (isset($args['uei'])) {
    $ueis = [(string) $args['uei']];
} elseif (isset($args['related'])) {
    // Roll-up sync: a parent entity + its component agencies. The agencies are the additional
    // UEIs the parent reports on its Single Audit (fac_additional_ueis — the "Related UEIs" in
    // Entity Info / the usa_awards rollup). Those members sit outside the findings-scoped queue,
    // so this is how a state's full USAspending footprint gets pulled. Includes the state_uei
    // succession group too. Usage: php sync_usa.php --related=GQ46SB5L2HK4
    $root = (string) $args['related'];
    $set  = [$root];
    $g = $pdo->prepare("SELECT ueis FROM state_uei WHERE ueis LIKE ?");
    $g->execute(['%' . $root . '%']);
    if (($gu = $g->fetchColumn()) !== false && $gu !== null) {
        foreach (preg_split('/\R+/', (string) $gu) ?: [] as $u) { if (($u = trim($u)) !== '') $set[] = $u; }
    }
    $self = array_values(array_unique($set));
    $selfPh = implode(',', array_fill(0, count($self), '?'));
    $m = $pdo->prepare(
        "SELECT DISTINCT additional_uei FROM fac_additional_ueis WHERE auditee_uei IN ($selfPh)
         UNION
         SELECT DISTINCT related_uei FROM entity_related_uei WHERE uei IN ($selfPh)"
    );
    $m->execute(array_merge($self, $self));
    foreach ($m->fetchAll(PDO::FETCH_COLUMN) as $u) { if ($u !== null && $u !== '') $set[] = $u; }
    $ueis = array_values(array_unique(array_filter($set)));
} elseif ($oldest) {
    // Staggered nightly refresh (prod self-sync): take the N most-overdue recipients —
    // never-synced first (no usa_recipient row → NULL), then by oldest last_synced. Each run
    // advances the queue (synced UEIs go to the back), so a fixed nightly --limit cycles
    // through the whole set over time and picks up new recipients first. Bypasses the
    // recent-skip below. The universe is findings recipients UNION rollup component agencies
    // (fac_additional_ueis) — so a parent's members are kept current by the nightly too, no
    // manual --where=members needed for steady state (that mode is just for a one-off backfill).
    $lim = max(1, (int) ($args['limit'] ?? 700));
    $ueis = $pdo->query(
        "SELECT u.uei
         FROM (
             SELECT DISTINCT auditee_uei uei   FROM fac_findings        WHERE auditee_uei   IS NOT NULL
             UNION
             SELECT DISTINCT additional_uei uei FROM fac_additional_ueis WHERE additional_uei IS NOT NULL AND additional_uei <> ''
             UNION
             SELECT DISTINCT related_uei uei    FROM entity_related_uei  WHERE related_uei IS NOT NULL AND related_uei <> ''
         ) u
         LEFT JOIN usa_recipient r ON r.uei = u.uei
         ORDER BY (r.last_synced IS NOT NULL), r.last_synced ASC, u.uei
         LIMIT $lim"
    )->fetchAll(PDO::FETCH_COLUMN);
} else {
    $where = $args['where'] ?? 'findings';
    $sql = match ($where) {
        'all'       => "SELECT uei FROM entity",
        'truncated' => "SELECT uei FROM usa_recipient WHERE sync_truncated = 1",   // re-pull capped recipients in full
        // Rollup component agencies (additional UEIs reported on a Single Audit) — these sit
        // OUTSIDE the findings queue, so a state/non-profit/etc. parent's full footprint never
        // syncs without this. 'members' = all of them; 'state-members' = just state parents'.
        'members'       => "SELECT DISTINCT additional_uei FROM fac_additional_ueis WHERE additional_uei IS NOT NULL AND additional_uei <> ''",
        'state-members' => "SELECT DISTINCT au.additional_uei FROM fac_additional_ueis au "
                         . "JOIN fac_general g ON g.auditee_uei = au.auditee_uei AND g.is_active = 1 "
                         . "WHERE g.entity_type = 'state' AND au.additional_uei IS NOT NULL AND au.additional_uei <> ''",
        'serious'   => "SELECT DISTINCT auditee_uei FROM fac_findings WHERE auditee_uei IS NOT NULL "
                     . "AND (is_material_weakness=1 OR is_questioned_costs=1 OR is_repeat_finding=1)",
        default     => "SELECT DISTINCT auditee_uei FROM fac_findings WHERE auditee_uei IS NOT NULL",
    };
    if (isset($args['limit'])) $sql .= ' LIMIT ' . (int) $args['limit'];
    $ueis = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
}

// resumable: skip UEIs synced recently (NOT in --oldest mode — there we deliberately
// refresh the most-overdue N regardless, to keep the staggered cycle moving)
$recent = [];
if (!$oldest) {
    foreach ($pdo->query("SELECT uei FROM usa_recipient WHERE last_synced >= (UTC_TIMESTAMP() - INTERVAL $maxage DAY)")
                 ->fetchAll(PDO::FETCH_COLUMN) as $u) {
        $recent[$u] = true;
    }
}

$total = count($ueis); $done = 0; $awards = 0; $started = time(); $lastProg = $started;
echo "USAspending sync: $total UEIs (skip those synced < $maxage days)\n";
$logId = RunLog::start($pdo, 'usaspending', 'prime_awards', 'usa_award');   // best-effort; finalized below

$delAwards = $pdo->prepare("DELETE FROM usa_award WHERE recipient_uei = ?");
$markDone  = $pdo->prepare("UPDATE usa_recipient SET name = COALESCE(?, name), sync_truncated = ?, last_synced = UTC_TIMESTAMP() WHERE uei = ?");
// flag the hub: has_usa was never set anywhere, which left the SAM reconcile's
// "no source left -> delete entity" cascade theoretically able to wipe cached
// USAspending data (entity deletes cascade through usa_recipient).
$markUsa   = $pdo->prepare("UPDATE entity SET has_usa = 1 WHERE uei = ?");

foreach ($ueis as $uei) {
    if (isset($recent[$uei])) { $done++; continue; }
    try {
        $up->insert('usa_recipient', ['uei' => $uei]);   // ensure FK target exists (last_synced set at end)
        // Replace this recipient's awards atomically: a mid-recipient failure (a throwing award
        // group, a dropped connection) otherwise auto-commits the DELETE and leaves partial/zero
        // awards — wrong obligation totals until the next --oldest cycle re-pulls it. Roll back instead.
        $pdo->beginTransaction();
        $delAwards->execute([$uei]);
        $name = null;
        $truncated = false;
        foreach (ASSIST_GROUPS as $category => $grp) {
            $isLoan = ($category === 'loan');
            $page = 1;
            do {
                $res = usa_post('/api/v2/search/spending_by_award/', [
                    'filters' => [
                        'recipient_search_text' => [$uei],
                        'award_type_codes'      => $grp['codes'],
                        // action_date floor — keep only awards active in/after entity-FY2022 (see $usaSince)
                        'time_period'           => [['start_date' => $usaSince, 'end_date' => gmdate('Y-m-d', strtotime('+1 year'))]],
                    ],
                    'fields'  => array_merge(
                        ['Award ID', 'Recipient Name', 'Recipient UEI', 'Awarding Agency',
                         'Awarding Sub Agency', 'Start Date', 'End Date', 'Base Obligation Date',
                         'Assistance Listings', 'Award Type'],
                        $grp['money']
                    ),
                    'page' => $page, 'limit' => 100, 'sort' => $grp['sort'], 'order' => 'desc',
                ]);
                foreach (($res['results'] ?? []) as $a) {
                    if (($a['Recipient UEI'] ?? null) !== $uei) continue; // search is fuzzy
                    $aid = s($a['generated_internal_id'] ?? null);
                    if ($aid === null) continue;
                    $name ??= s($a['Recipient Name'] ?? null);
                    $up->insert('usa_award', [
                        'award_id'                => $aid,
                        'category'                => $category,
                        'recipient_uei'           => $uei,
                        'recipient_name'          => s($a['Recipient Name'] ?? null),
                        'award_type_description'  => s($a['Award Type'] ?? null),
                        'fain'                    => s($a['Award ID'] ?? null),
                        'awarding_toptier_agency' => s($a['Awarding Agency'] ?? null),
                        'awarding_subtier_agency' => s($a['Awarding Sub Agency'] ?? null),
                        'awarding_agency_id'      => num($a['awarding_agency_id'] ?? null),
                        'total_obligation'        => $isLoan ? null : num($a['Award Amount'] ?? null),
                        'total_outlay'            => $isLoan ? null : num($a['Total Outlays'] ?? null),
                        'total_loan_value'        => $isLoan ? num($a['Loan Value'] ?? null) : null,
                        'total_subsidy_cost'      => $isLoan ? num($a['Subsidy Cost'] ?? null) : null,
                        'date_signed'             => dd($a['Base Obligation Date'] ?? null),  // award's base obligation action date
                        'period_start_date'       => dd($a['Start Date'] ?? null),
                        'period_end_date'         => dd($a['End Date'] ?? null),
                        'last_synced'             => gmdate('Y-m-d H:i:s'),
                    ]);
                    $awards++;
                    if (!$SKIP_CFDA) foreach (($a['Assistance Listings'] ?? []) as $al) {
                        $cn = s($al['cfda_number'] ?? null);
                        if ($cn === null) continue;
                        $up->insert('usa_award_cfda', [
                            'award_id'    => $aid,
                            'cfda_number' => $cn,
                            'cfda_title'  => s($al['cfda_program_title'] ?? null),
                        ]);
                    }
                }
                $more = (bool) ($res['page_metadata']['hasNext'] ?? false);
                if ($more && $maxPages > 0 && $page >= $maxPages) {   // $maxPages 0 = no cap (pull all)
                    $truncated = true;   // largest awards kept; long tail beyond the cap dropped
                    fwrite(STDERR, "  $uei: $category capped at $maxPages pages (sync_truncated=1)\n");
                    $more = false;
                }
                $page++;
            } while ($more);
        }
        $markDone->execute([$name, $truncated ? 1 : 0, $uei]); // mark synced only after all groups succeeded
        $markUsa->execute([$uei]);
        $pdo->commit();
        usleep(500000); // gentle pacing to avoid USAspending throttling
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();   // keep the recipient's prior awards intact
        fwrite(STDERR, "  $uei error: " . substr($e->getMessage(), 0, 80) . "\n");
    }
    // Checkpoint often (every 25 UEIs OR 30s), not every 200: the 28-min host reaper often kills a
    // run before UEI 200, and with the old cadence that run logged rows_upserted=0 even when it had
    // loaded thousands of awards — making a productive-but-cut-off run look like a no-op. Frequent
    // checkpoints keep the recorded rows/progress honest so the admin history can trust them.
    $done++;
    if ($done % 25 === 0 || time() - $lastProg >= 30) {
        printf("  %d/%d UEIs, %d awards (%.1f UEI/s)\n", $done, $total, $awards, $done / max(1, time() - $started));
        RunLog::progress($pdo, $logId, $awards, "$done/$total UEIs · $awards awards");
        $lastProg = time();
    }
}
RunLog::finish($pdo, $logId, 'usaspending', 'prime_awards', 'usa_award', 'ok', $awards, "$done/$total UEIs processed · $awards awards loaded");
printf("Done. %d UEIs processed, %d awards loaded.\n", $done, $awards);
