<?php
declare(strict_types=1);

/**
 * AERO — USAspending TRANSACTION sync (CLI). Per recipient, pulls prime-award transactions
 * (spending_by_transaction) and aggregates each award's federal_action_obligation by calendar
 * month into usa_award_txn_month. That lets the USAspending tab split an award's obligations
 * across fiscal years by the transaction action_date (matching USAspending.gov) instead of
 * dumping the whole award into its base obligation date's FY.
 *
 * Only transactions for awards already in usa_award are stored (FK), so run AFTER sync_usa.php.
 * Idempotent: re-deletes a recipient's months before reinserting. Honors the same FY2022+
 * action_date floor as the award sync (--since), so the month data aligns with the retained awards.
 *
 * Usage:
 *   php sync_usa_txns.php --uei=EK7ENJE97829      # one recipient
 *   php sync_usa_txns.php --related=GQ46SB5L2HK4  # a parent + its component agencies (rollup group)
 *   php sync_usa_txns.php --where=findings --limit=500
 *   php sync_usa_txns.php --oldest --limit=700    # staggered nightly chunk
 */

$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
require $root . '/lib/Http.php';
Env::load(dirname($root, 2) . '/.env');
Env::load(dirname($root) . '/.env');

function s($v): ?string { if ($v === null) return null; $v = trim((string) $v); return $v === '' ? null : $v; }
function num($v) { return is_numeric($v) ? $v + 0 : null; }

// Assistance award-type codes, grouped (USAspending rejects mixing groups in one query). Same
// split as sync_usa.php; obligations live on grants + direct payments + loans alike.
const TXN_GROUPS = [['02', '03', '04', '05'], ['06', '10'], ['07', '08']];

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
$pdo = Db::connect();

if (!(int) $pdo->query("SELECT GET_LOCK('aero_sync_usa_txns', 0)")->fetchColumn()) {
    fwrite(STDERR, "Another sync_usa_txns.php run is already active; exiting.\n");
    exit(1);
}

$since    = (string) ($args['since'] ?? '2021-07-01');     // action_date floor (matches sync_usa.php)
$endDate  = gmdate('Y-m-d', strtotime('+1 year'));
$maxPages = max(0, (int) ($args['maxpages'] ?? 0));        // 0 = NO CAP (pull every page — no truncation)

// Build the recipient queue (mirrors sync_usa.php's modes).
if (isset($args['uei'])) {
    $ueis = [(string) $args['uei']];
} elseif (isset($args['related'])) {
    $root_uei = (string) $args['related'];
    $set = [$root_uei];
    $g = $pdo->prepare("SELECT ueis FROM state_uei WHERE ueis LIKE ?");
    $g->execute(['%' . $root_uei . '%']);
    if (($gu = $g->fetchColumn()) !== false && $gu !== null) {
        foreach (preg_split('/\R+/', (string) $gu) ?: [] as $u) { if (($u = trim($u)) !== '') $set[] = $u; }
    }
    $self = array_values(array_unique($set));
    $m = $pdo->prepare("SELECT DISTINCT additional_uei FROM fac_additional_ueis WHERE auditee_uei IN ("
        . implode(',', array_fill(0, count($self), '?')) . ")");
    $m->execute($self);
    foreach ($m->fetchAll(PDO::FETCH_COLUMN) as $u) { if ($u !== null && $u !== '') $set[] = $u; }
    $ueis = array_values(array_unique(array_filter($set)));
} elseif (isset($args['oldest'])) {
    // recipients with USAspending awards but the most-stale (or missing) txn-month data
    $lim = max(1, (int) ($args['limit'] ?? 700));
    $ueis = $pdo->query(
        "SELECT a.recipient_uei
         FROM (SELECT DISTINCT recipient_uei FROM usa_award WHERE recipient_uei IS NOT NULL) a
         LEFT JOIN (SELECT DISTINCT ua.recipient_uei FROM usa_award_txn_month m
                    JOIN usa_award ua ON ua.award_id = m.award_id) t ON t.recipient_uei = a.recipient_uei
         ORDER BY (t.recipient_uei IS NOT NULL), a.recipient_uei
         LIMIT $lim"
    )->fetchAll(PDO::FETCH_COLUMN);
} else {
    $where = $args['where'] ?? 'findings';
    $sql = match ($where) {
        'all'           => "SELECT DISTINCT recipient_uei FROM usa_award WHERE recipient_uei IS NOT NULL",
        // rollup component agencies (see sync_usa.php) — sync their transaction months once their
        // awards exist; 'members' = all, 'state-members' = just state parents'.
        'members'       => "SELECT DISTINCT additional_uei FROM fac_additional_ueis WHERE additional_uei IS NOT NULL AND additional_uei <> ''",
        'state-members' => "SELECT DISTINCT au.additional_uei FROM fac_additional_ueis au "
                         . "JOIN fac_general g ON g.auditee_uei = au.auditee_uei AND g.is_active = 1 "
                         . "WHERE g.entity_type = 'state' AND au.additional_uei IS NOT NULL AND au.additional_uei <> ''",
        default         => "SELECT DISTINCT auditee_uei FROM fac_findings WHERE auditee_uei IS NOT NULL",
    };
    if (isset($args['limit'])) $sql .= ' LIMIT ' . (int) $args['limit'];
    $ueis = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
}

$total = count($ueis); $done = 0; $months = 0; $started = time();
echo "USAspending transaction sync: $total recipients (action_date >= $since)\n";

$delMonths = $pdo->prepare(
    "DELETE m FROM usa_award_txn_month m JOIN usa_award a ON a.award_id = m.award_id WHERE a.recipient_uei = ?"
);

foreach ($ueis as $uei) {
    try {
        // award_ids we may store months for (FK target); transactions for un-synced awards are skipped
        $own = $pdo->prepare("SELECT award_id FROM usa_award WHERE recipient_uei = ?");
        $own->execute([$uei]);
        $awardIds = array_flip($own->fetchAll(PDO::FETCH_COLUMN));
        if (!$awardIds) { $done++; continue; }

        $agg = [];   // award_id => ['YYYY-MM-01' => sum]
        foreach (TXN_GROUPS as $codes) {
            $page = 1;
            do {
                $res = usa_post('/api/v2/search/spending_by_transaction/', [
                    'filters' => [
                        'recipient_search_text' => [$uei],
                        'award_type_codes'      => $codes,
                        'time_period'           => [['start_date' => $since, 'end_date' => $endDate]],
                    ],
                    'fields' => ['Award ID', 'Recipient UEI', 'Action Date', 'Transaction Amount', 'cfda_number', 'Award Type'],
                    'page'   => $page, 'limit' => 100, 'sort' => 'Action Date', 'order' => 'desc',
                ]);
                foreach (($res['results'] ?? []) as $t) {
                    if (($t['Recipient UEI'] ?? null) !== $uei) continue;          // search is fuzzy
                    $aid = s($t['generated_internal_id'] ?? null);
                    if ($aid === null || !isset($awardIds[$aid])) continue;        // FK / not synced
                    $d = s($t['Action Date'] ?? null);
                    if ($d === null) continue;
                    $amt = num($t['Transaction Amount'] ?? null);
                    if ($amt === null) continue;
                    $ym = substr($d, 0, 7) . '-01';
                    $cfda = s($t['cfda_number'] ?? null) ?? '';      // transaction's own program (Assistance Listing)
                    $k = $ym . '|' . $cfda;
                    $agg[$aid][$k] = ($agg[$aid][$k] ?? 0) + $amt;
                }
                $more = (bool) ($res['page_metadata']['hasNext'] ?? false);
                if ($more && $maxPages > 0 && $page >= $maxPages) {   // $maxPages 0 = no cap (pull all)
                    fwrite(STDERR, "  $uei: capped at $maxPages pages\n");
                    $more = false;
                }
                $page++;
            } while ($more);
        }

        $delMonths->execute([$uei]);
        // flatten award => (month|cfda) => sum into rows, insert in chunks of 1,000 (4,000 params)
        $flat = [];
        foreach ($agg as $aid => $byKey) {
            foreach ($byKey as $k => $sum) {
                [$ym, $cfda] = explode('|', $k, 2);
                $flat[] = [$aid, $cfda, $ym, round((float) $sum, 2)];
            }
        }
        for ($off = 0; $off < count($flat); $off += 1000) {
            $chunk = array_slice($flat, $off, 1000);
            $ph = implode(',', array_fill(0, count($chunk), '(?,?,?,?)'));
            $vals = [];
            foreach ($chunk as $r) array_push($vals, $r[0], $r[1], $r[2], $r[3]);
            $pdo->prepare("INSERT INTO usa_award_txn_month (award_id, cfda, ym, obligation) VALUES $ph")->execute($vals);
            $months += count($chunk);
        }
        usleep(300000); // gentle pacing
    } catch (Throwable $e) {
        fwrite(STDERR, "  $uei error: " . substr($e->getMessage(), 0, 100) . "\n");
    }
    if (++$done % 100 === 0) {
        printf("  %d/%d recipients, %d award-months (%.1f rec/s)\n", $done, $total, $months, $done / max(1, time() - $started));
    }
}
printf("Done. %d recipients, %d award-months loaded.\n", $done, $months);
