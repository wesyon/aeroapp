<?php
declare(strict_types=1);

/**
 * AERO — USAspending OUTLAY sync (CLI). Reconstructs each prime award's outlays by CALENDAR MONTH
 * from File C (Account Breakdown by Award), so the USAspending tab can show outlays on ANY fiscal
 * year — including a non-federal entity FY (e.g. 7/1–6/30) — exactly like obligations already do.
 *
 * Why this is separate from the obligation sync: obligations are transaction-level and land natively
 * on their action_date (sync_usa_txns.php → usa_award_txn_month). Outlays are NOT — USAspending has
 * no per-transaction outlay. Outlays only exist as account-level File C figures that are CUMULATIVE
 * within the FEDERAL fiscal year (period 2 = cumulative-through-Nov … period 12 = through-Sep) and
 * RESET every Oct 1. So per award we:
 *   1. pull /api/v2/awards/{id}/funding/ (all pages),
 *   2. sum gross_outlay_amount across every federal-account/object-class/program row, by (FY, period),
 *   3. difference consecutive periods WITHIN each FY to recover the calendar-month outlay (never
 *      across the Oct boundary, because the counter resets),
 *   4. map the federal reporting period to its calendar month and store the delta in
 *      usa_award_outlay_month. Deltas may be negative (downward outlay adjustments / deobligations).
 * The tab then buckets those monthly outlays into the entity or federal FY at view time.
 *
 * Scale note: File C is per-AWARD (the /funding/ endpoint needs an award_id), unlike the obligation
 * endpoints which are per-recipient. There are ~800k awards carrying outlays, so a full backfill is
 * a large crawl. This sync is therefore per-award RESUMABLE (usa_award.outlay_synced) and PRIORITISED
 * (biggest total_outlay first) — loans and zero-outlay awards are skipped, and the award's lifetime
 * usa_award.total_outlay remains the graceful fallback for awards not yet (or never) File C-linked.
 *
 * Usage:
 *   php sync_usa_outlays.php --uei=EK7ENJE97829       # one recipient's awards
 *   php sync_usa_outlays.php --award=ASST_NON_..._075 # a single award (debug)
 *   php sync_usa_outlays.php --related=GQ46SB5L2HK4   # a parent + its component agencies (rollup)
 *   php sync_usa_outlays.php --where=findings --limit=2000
 *   php sync_usa_outlays.php --where=state            # state governments (+ their component agencies) first
 *   php sync_usa_outlays.php --where=all --limit=5000
 *   php sync_usa_outlays.php --oldest --limit=4000    # staggered nightly chunk (most-overdue first)
 *   php sync_usa_outlays.php --where=all --shard=0/12 --sleepms=50   # one of 12 parallel backfill workers
 */

$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
require $root . '/lib/Http.php';
require $root . '/lib/RunLog.php';
Env::load(dirname($root, 2) . '/.env');
Env::load(dirname($root) . '/.env');

function s($v): ?string { if ($v === null) return null; $v = trim((string) $v); return $v === '' ? null : $v; }

// FAIL-FAST for this per-award crawl: one quick internal retry (Http tries=2 → ~2s worst case) then
// give up and let the caller DEFER the award — it's fully resumable (freshSkip re-queues it next pass).
// The old 5-try outer loop wrapping Http's own 4 retries could stall ONE worker ~100s on a single
// dropped connection; under concurrency that cascaded into ~0 throughput (all workers stuck in backoff).
// Deferring a dropped award is far cheaper than stalling a worker on it.
function usa_post(string $path, array $body): array
{
    [, , $d] = Http::postJson("https://api.usaspending.gov$path", $body, [], 2);
    return is_array($d) ? $d : [];
}

/**
 * Pull one award's File C funding rows and reduce to calendar-month outlay deltas.
 * @return array<string,float>  ['YYYY-MM-01' => outlay delta] (exact-zero deltas dropped; negatives kept)
 */
function outlay_months(string $awardId, int $maxPages): array
{
    $cum = [];   // [fy][fiscal_period] => summed gross_outlay_amount (across all accounts/OC/PA/DEFC)
    $page = 1;
    do {
        $res = usa_post('/api/v2/awards/funding/', [
            'award_id' => $awardId,
            'page'     => $page, 'limit' => 100,
            'sort'     => 'reporting_fiscal_date', 'order' => 'asc',
        ]);
        foreach (($res['results'] ?? []) as $r) {
            $go = $r['gross_outlay_amount'] ?? null;
            if ($go === null || !is_numeric($go)) continue;         // obligation-only rows carry null here
            $fy = (int) ($r['reporting_fiscal_year'] ?? 0);
            $fm = (int) ($r['reporting_fiscal_month'] ?? 0);        // 1=Oct … 12=Sep (federal fiscal period)
            if ($fy <= 0 || $fm < 1 || $fm > 12) continue;
            $cum[$fy][$fm] = ($cum[$fy][$fm] ?? 0) + (float) $go;
        }
        $more = (bool) ($res['page_metadata']['hasNext'] ?? false);
        if ($more && $maxPages > 0 && $page >= $maxPages) $more = false;
        $page++;
    } while ($more);

    $out = [];
    foreach ($cum as $fy => $byPeriod) {
        ksort($byPeriod);          // ascending reporting period within the FY (cumulative series)
        $prev = 0.0;
        foreach ($byPeriod as $fm => $cumVal) {
            $delta = round($cumVal - $prev, 2);   // period-over-period = the calendar month's outlay
            $prev  = $cumVal;
            // Federal reporting period -> calendar month/year. fm 1=Oct(prev cal yr) … 12=Sep.
            $calMonth = (($fm + 8) % 12) + 1;
            $calYear  = ($fm <= 3) ? $fy - 1 : $fy;
            $ym = sprintf('%04d-%02d-01', $calYear, $calMonth);
            if ($delta == 0.0) continue;          // keep negatives; drop exact zeros to bound rows
            $out[$ym] = round(($out[$ym] ?? 0) + $delta, 2);
        }
    }
    return $out;
}

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$pdo = Db::connect();

// Parallel backfill: --shard=i/N splits the award universe N ways by CRC32(award_id) so N workers can
// crawl disjoint slices at once (each takes its OWN lock, not the global one). The /funding/ endpoint
// is per-award and mostly network wait, so workers scale throughput near-linearly until USAspending
// throttles (429s, which the HTTP layer already retries). This runs on the LOCAL IP, so over-
// parallelising only self-limits this backfill — it can't get prod's nightly IP throttled.
$shardI = null; $shardN = null;
if (isset($args['shard']) && preg_match('#^(\d+)/(\d+)$#', (string) $args['shard'], $sm)) {
    $shardN = (int) $sm[2]; $shardI = (int) $sm[1];
    if ($shardN < 1 || $shardI < 0 || $shardI >= $shardN) { fwrite(STDERR, "bad --shard=i/N (need 0<=i<N)\n"); exit(1); }
}
$shardSql = $shardN ? " AND CRC32(a.award_id) % $shardN = $shardI" : '';
$lockName = 'aero_sync_usa_outlays' . ($shardN ? "_{$shardI}of{$shardN}" : '');
if (!(int) $pdo->query("SELECT GET_LOCK('$lockName', 0)")->fetchColumn()) {
    fwrite(STDERR, "Another sync_usa_outlays.php run holds '$lockName'; exiting.\n");
    exit(1);
}

$maxage   = (int) ($args['maxage'] ?? 30);
$maxPages = max(0, (int) ($args['maxpages'] ?? 0));            // funding pages per award; 0 = all
// Per-award fan-out makes this heavy, so a manual run is capped by default; raise with --limit, or
// --limit=0 to lift the cap entirely. The nightly passes an explicit --limit.
$limit    = isset($args['limit']) ? max(0, (int) $args['limit']) : 2000;
$sleepUs  = max(0, (int) ($args['sleepms'] ?? 200)) * 1000;    // per-award pacing (ms); lower it when sharding

// Only awards that can HAVE outlays: assistance grants/direct payments with a nonzero lifetime
// outlay on file. Loans (no outlay field) and zero/NULL-outlay awards are skipped — a /funding/
// call for them returns nothing useful.
const MATERIAL = "a.category <> 'loan' AND a.total_outlay IS NOT NULL AND a.total_outlay <> 0";
// Don't re-pull awards done within --maxage days (skipped in --oldest mode, which deliberately
// refreshes the most-overdue regardless to keep the staggered cycle advancing).
$freshSkip = "(a.outlay_synced IS NULL OR a.outlay_synced < (UTC_TIMESTAMP() - INTERVAL $maxage DAY))";
$lim = $limit > 0 ? " LIMIT $limit" : '';

$oldest = isset($args['oldest']);
$params = [];
if (isset($args['award'])) {
    $ids = [(string) $args['award']];
} elseif (isset($args['uei']) || isset($args['related'])) {
    // Resolve a recipient UEI set (mirrors sync_usa.php): a UEI's crosswalk group, or a parent +
    // its component agencies (fac_additional_ueis), then take that set's material awards, biggest first.
    $seed = (string) ($args['uei'] ?? $args['related']);
    $set  = [$seed];
    $g = $pdo->prepare("SELECT ueis FROM state_uei WHERE ueis LIKE ?");
    $g->execute(['%' . $seed . '%']);
    if (($gu = $g->fetchColumn()) !== false && $gu !== null) {
        foreach (preg_split('/\R+/', (string) $gu) ?: [] as $u) { if (($u = trim($u)) !== '') $set[] = $u; }
    }
    if (isset($args['related'])) {
        $self = array_values(array_unique($set));
        $m = $pdo->prepare("SELECT DISTINCT additional_uei FROM fac_additional_ueis WHERE auditee_uei IN ("
            . implode(',', array_fill(0, count($self), '?')) . ")");
        $m->execute($self);
        foreach ($m->fetchAll(PDO::FETCH_COLUMN) as $u) { if ($u !== null && $u !== '') $set[] = $u; }
    }
    $set = array_values(array_unique(array_filter($set)));
    $in  = implode(',', array_fill(0, count($set), '?'));
    $params = $set;
    $ids = $pdo->prepare(
        "SELECT a.award_id FROM usa_award a
         WHERE a.recipient_uei IN ($in) AND " . MATERIAL . " AND $freshSkip$shardSql
         ORDER BY a.total_outlay DESC$lim"
    );
    $ids->execute($params);
    $ids = $ids->fetchAll(PDO::FETCH_COLUMN);
} elseif ($oldest) {
    // Staggered nightly: the most-overdue material awards first (never-synced, then oldest), so a
    // fixed nightly --limit cycles through the whole backlog over time, biggest outlays leading.
    $ids = $pdo->query(
        "SELECT a.award_id FROM usa_award a
         WHERE " . MATERIAL . "$shardSql
         ORDER BY (a.outlay_synced IS NOT NULL), a.outlay_synced ASC, a.total_outlay DESC$lim"
    )->fetchAll(PDO::FETCH_COLUMN);
} elseif (($args['where'] ?? 'findings') === 'state') {
    // State governments first — resolve the UEI set ONCE (state-govt auditees, entity_type='state',
    // PLUS the component agencies they roll up as additional UEIs, where a state's awards actually
    // sit), then filter awards by INDEXED recipient_uei IN(...). Doing it as an inline IN-subquery
    // ran per-row over ~1M awards (a dependent subquery — minutes); this is the fast path.
    $stateUeis = $pdo->query(
        "SELECT auditee_uei uei FROM fac_general WHERE entity_type = 'state' AND is_active = 1 AND auditee_uei IS NOT NULL
         UNION
         SELECT au.additional_uei FROM fac_additional_ueis au
           JOIN fac_general g ON g.auditee_uei = au.auditee_uei AND g.is_active = 1
           WHERE g.entity_type = 'state' AND au.additional_uei IS NOT NULL AND au.additional_uei <> ''"
    )->fetchAll(PDO::FETCH_COLUMN);
    $stateUeis = array_values(array_unique(array_filter($stateUeis)));
    if (!$stateUeis) {
        $ids = [];
    } else {
        $in = implode(',', array_fill(0, count($stateUeis), '?'));
        $q = $pdo->prepare(
            "SELECT a.award_id FROM usa_award a
             WHERE a.recipient_uei IN ($in) AND " . MATERIAL . " AND $freshSkip$shardSql
             ORDER BY a.total_outlay DESC$lim"
        );
        $q->execute($stateUeis);
        $ids = $q->fetchAll(PDO::FETCH_COLUMN);
    }
} else {
    // --where=all (whole material universe) or the default findings scope.
    $scope = ($args['where'] ?? 'findings') === 'all' ? '1'
           : "EXISTS (SELECT 1 FROM fac_findings f WHERE f.auditee_uei = a.recipient_uei)";
    $ids = $pdo->query(
        "SELECT a.award_id FROM usa_award a
         WHERE " . MATERIAL . " AND $scope AND $freshSkip$shardSql
         ORDER BY a.total_outlay DESC$lim"
    )->fetchAll(PDO::FETCH_COLUMN);
}

$total = count($ids); $done = 0; $rows = 0; $started = time(); $lastBeat = $started;
echo "USAspending outlay sync: $total awards (File C monthly outlays)\n";
$logId = RunLog::start($pdo, 'usaspending', 'outlay_months', 'usa_award_outlay_month');

$del  = $pdo->prepare("DELETE FROM usa_award_outlay_month WHERE award_id = ?");
$mark = $pdo->prepare("UPDATE usa_award SET outlay_synced = UTC_TIMESTAMP() WHERE award_id = ?");

foreach ($ids as $awardId) {
    try {
        $months = outlay_months($awardId, $maxPages);
        $del->execute([$awardId]);
        if ($months) {
            // insert this award's month rows in one statement (each award has ~a few dozen at most)
            $ph = implode(',', array_fill(0, count($months), '(?,?,?)'));
            $vals = [];
            foreach ($months as $ym => $amt) { array_push($vals, $awardId, $ym, $amt); }
            $pdo->prepare("INSERT INTO usa_award_outlay_month (award_id, ym, outlay) VALUES $ph")->execute($vals);
            $rows += count($months);
        }
        $mark->execute([$awardId]);   // mark done even when empty, so it isn't recrawled next run
        if ($sleepUs) usleep($sleepUs);   // per-award pacing (--sleepms); lower it when sharding
    } catch (Throwable $e) {
        fwrite(STDERR, "  $awardId error: " . substr($e->getMessage(), 0, 100) . "\n");
    }
    // Heartbeat on award count OR every ~30s — the time-based beat keeps progress_at fresh through the
    // giant-award phase (one mega-grant can take minutes across many File C pages), so the run stays
    // 'running' instead of looking stalled/interrupted. Reports AWARDS done (not month-rows) so the
    // admin card's "N awards so far" and the done/total coverage bar (both in awards) stay consistent.
    if (++$done % 50 === 0 || (time() - $lastBeat) >= 30) {
        $lastBeat = time();
        printf("  %d/%d awards, %d month-rows (%.1f award/s)\n", $done, $total, $rows, $done / max(1, time() - $started));
        RunLog::progress($pdo, $logId, $done, "$done/$total awards · $rows month-rows");
    }
}
RunLog::finish($pdo, $logId, 'usaspending', 'outlay_months', 'usa_award_outlay_month', 'ok', $done, "$done/$total awards processed · $rows month-rows loaded");
printf("Done. %d awards processed, %d outlay-month rows loaded.\n", $done, $rows);
