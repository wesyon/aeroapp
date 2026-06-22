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
 * Dry-run by default (prints what WOULD be deleted). --commit deletes (batched); --optimize then
 * OPTIMIZEs the tables so InnoDB returns the freed space to the OS.
 *
 * Usage:
 *   php prune_usa_awards.php                       # dry run, default cutoff 2021-07-01
 *   php prune_usa_awards.php --before=2020-07-01   # ultra-safe biennial floor (dry run)
 *   php prune_usa_awards.php --commit              # delete in batches
 *   php prune_usa_awards.php --commit --optimize   # delete + reclaim disk
 */

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

$cf = $pdo->prepare(
    "SELECT COUNT(*) FROM usa_award_cfda c JOIN usa_award a ON a.award_id = c.award_id
     WHERE COALESCE(a.period_end_date, a.period_start_date) < :before"
);
$cf->execute([':before' => $before]);
printf("usa_award_cfda cascade   : %s rows\n", number_format((int) $cf->fetchColumn()));

if (!isset($args['commit'])) {
    echo "\nDRY RUN — nothing deleted. Re-run with --commit to apply (add --optimize to reclaim disk).\n";
    exit(0);
}

echo "\nDeleting in batches of 5,000…\n";
$batch = $pdo->prepare("DELETE FROM usa_award WHERE $where LIMIT 5000");
$done = 0;
do {
    $batch->execute([':before' => $before]);
    $n = $batch->rowCount();
    $done += $n;
    if ($n) printf("  %s / %s\n", number_format($done), number_format($toDelete));
} while ($n > 0);
printf("Done. Deleted %s usa_award rows (usa_award_cfda cascaded).\n", number_format($done));

if (isset($args['optimize'])) {
    echo "Optimizing tables (reclaims freed space; rebuilds the table)…\n";
    foreach (['usa_award', 'usa_award_cfda'] as $t) {
        $pdo->query("OPTIMIZE TABLE $t");
        echo "  optimized $t\n";
    }
}
