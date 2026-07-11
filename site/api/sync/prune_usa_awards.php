<?php
declare(strict_types=1);
/**
 * AERO — prune old USAspending awards to bound DB size. Keeps only awards whose performance
 * period is still active on/after the cutoff (entity-FY2022 floor by default):
 *     COALESCE(period_end_date, period_start_date) >= :before
 * Awards with NO period dates at all are KEPT (can't prove inactive). usa_award_cfda rows
 * cascade-delete via the FK (fk_awardcfda_award ON DELETE CASCADE).
 *
 * Cut by ACTIVITY (period overlap), not obligation date: an award can only appear in an entity's
 * FY2022+ Single Audit if its performance period reaches into FY2022, so anything that ended
 * earlier is irrelevant to the audits we hold — and date_signed is too sparsely populated to cut on.
 *
 * Also reaps ORPHANED month rows (usa_award_txn_month / usa_award_outlay_month whose award is
 * gone) — those tables have no cascade FK, so sync_usa's per-recipient DELETE+reinsert and this
 * prune leave orphans that no reader can reach. The reap runs on --commit (after the award prune).
 *
 * Dry-run by default (prints what WOULD be deleted). --commit deletes (batched); --optimize then
 * OPTIMIZEs the tables so InnoDB returns the freed space to the OS.
 *
 * Usage:
 *   php prune_usa_awards.php                       # dry run, default cutoff 2021-07-01
 *   php prune_usa_awards.php --before=2020-07-01   # ultra-safe biennial floor (dry run)
 *   php prune_usa_awards.php --commit              # prune awards + reap orphan months (batched)
 *   php prune_usa_awards.php --reap-only --commit  # reap orphan month rows only (no award prune)
 *   php prune_usa_awards.php --commit --optimize   # delete + reclaim disk (needs temp space)
 */

ini_set('memory_limit', '1G');   // the orphan reap loads up to ~1M distinct award_ids into PHP
$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
Env::load(dirname($root, 2) . '/.env');   // above web root (prod)
Env::load(dirname($root) . '/.env');      // repo root (local)

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$before = (string) ($args['before'] ?? '2021-07-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $before)) {
    fwrite(STDERR, "--before must be YYYY-MM-DD\n");
    exit(1);
}
$pdo = Db::connect();

// Drop awards whose performance period ENDED before the cutoff. NULL period dates are kept
// (COALESCE -> NULL, and NULL < date is NULL/false), since we can't prove them inactive.
$where = "COALESCE(period_end_date, period_start_date) < :before";

$total = (int) $pdo->query("SELECT COUNT(*) FROM usa_award")->fetchColumn();
$st = $pdo->prepare("SELECT COUNT(*) FROM usa_award WHERE $where");
$st->execute([':before' => $before]);
$toDelete = (int) $st->fetchColumn();

echo "USAspending award prune — keep awards active since $before\n";
echo str_repeat('-', 60) . "\n";
printf("usa_award total          : %s\n", number_format($total));
printf("  keep (active/dateless) : %s\n", number_format($total - $toDelete));
printf("  DELETE (ended before)  : %s  (%.1f%%)\n", number_format($toDelete), $total ? 100 * $toDelete / $total : 0);

try {   // usa_award_cfda is LOCAL-ONLY (not shipped to prod) — count is informational
    $cf = $pdo->prepare(
        "SELECT COUNT(*) FROM usa_award_cfda c JOIN usa_award a ON a.award_id = c.award_id
         WHERE COALESCE(a.period_end_date, a.period_start_date) < :before"
    );
    $cf->execute([':before' => $before]);
    printf("usa_award_cfda cascade   : %s rows\n", number_format((int) $cf->fetchColumn()));
} catch (\PDOException $e) {
    echo "usa_award_cfda cascade   : (table absent — local-only)\n";
}

// Orphaned month rows: sync_usa's per-recipient DELETE+reinsert (and this prune) remove
// usa_award rows without cascading to the month tables, which deliberately have no FK. Every
// reader joins through usa_award, so orphans are dead weight — count now, reap on --commit.
$orphanTables = ['usa_award_txn_month', 'usa_award_outlay_month'];
echo str_repeat('-', 60) . "\n";
foreach ($orphanTables as $t) {
    $oc = (int) $pdo->query("SELECT COUNT(*) FROM `$t` m LEFT JOIN usa_award a ON a.award_id = m.award_id WHERE a.award_id IS NULL")->fetchColumn();
    printf("%-24s : %s orphan rows (reaped on --commit)\n", $t, number_format($oc));
}

if (!isset($args['commit'])) {
    echo "\nDRY RUN — nothing deleted. Re-run with --commit to apply (add --optimize to reclaim disk).\n";
    exit(0);
}

if (!isset($args['reap-only'])) {   // --reap-only: skip the award prune, just clean orphans
    echo "\nDeleting awards in batches of 5,000…\n";
    $batch = $pdo->prepare("DELETE FROM usa_award WHERE $where LIMIT 5000");
    $done = 0;
    do {
        $batch->execute([':before' => $before]);
        $n = $batch->rowCount();
        $done += $n;
        if ($n) printf("  %s / %s\n", number_format($done), number_format($toDelete));
    } while ($n > 0);
    printf("Done. Deleted %s usa_award rows (usa_award_cfda cascaded).\n", number_format($done));
}

// Reap orphaned month rows (award gone). Runs after the award prune so it also cleans up the
// orphans that prune just created. Batched anti-join; readers join through usa_award so this
// is always safe. NOTE: DELETE frees space for REUSE (caps growth) but does not shrink the
// .ibd file — add --optimize to reclaim it (needs temp space ~= table size; may not fit a
// quota-tight host, in which case reclaim during a maintenance window / host migration).
echo "\nReaping orphaned month rows…\n";
foreach ($orphanTables as $t) {
    // Collect the distinct orphan award_ids ONCE (a single anti-join scan), then delete their
    // rows in chunks keyed by award_id — the month table's leading PK column — so each chunk is
    // an index range-delete. (Multi-table DELETE ... JOIN doesn't accept LIMIT, and re-running a
    // LEFT-JOIN anti-join with LIMIT re-scans the whole table every batch; this avoids both.)
    $ids = $pdo->query("SELECT DISTINCT m.award_id FROM `$t` m
                        LEFT JOIN usa_award a ON a.award_id = m.award_id WHERE a.award_id IS NULL")
               ->fetchAll(PDO::FETCH_COLUMN);
    $d = 0;
    foreach (array_chunk($ids, 500) as $chunk) {
        $del = $pdo->prepare("DELETE FROM `$t` WHERE award_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")");
        $del->execute($chunk);
        $d += $del->rowCount();
    }
    printf("  %-24s reaped %s rows (%s orphan awards)\n", $t, number_format($d), number_format(count($ids)));
}

if (isset($args['optimize'])) {
    echo "Optimizing tables (reclaims freed space; rebuilds the table)…\n";
    foreach (['usa_award', 'usa_award_cfda', 'usa_award_txn_month', 'usa_award_outlay_month'] as $t) {
        $pdo->query("OPTIMIZE TABLE $t");
        echo "  optimized $t\n";
    }
}
