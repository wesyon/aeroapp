<?php
declare(strict_types=1);
/**
 * load_d2_txn.php — rebuild usa_award_txn_month from downloaded USAspending File D2
 * ("Financial Assistance" Award Data Archive, per-FY "All_Assistance_Full") zips. LOCAL tool.
 *
 * The throttle-free bulk fill for OBLIGATIONS: the archive zips are already on disk, so this is a
 * pure local parse — no API, no download server, no throttle. For each zip it streams every CSV
 * (zip://), maps columns BY HEADER (the archive files vary in column order, so positions are not
 * safe), keeps only rows whose assistance_award_unique_key is one of OUR awards, and stages
 * (award_id, cfda, month-of-action_date, federal_action_obligation). It then aggregates the stage
 * into a fresh txn_month and atomically RENAME-swaps it in, keeping the prior table as
 * usa_award_txn_month_old for rollback. Sums transactions per (award, month, cfda) = the monthly
 * obligation, exactly what the tab's action-date FY split reads.
 *
 * Usage: php -d memory_limit=4G api/sync/load_d2_txn.php [dir]
 *        (default dir: <repo>/references/All_D2_Assistance_ZIP)
 */
ini_set('memory_limit', '4G');
$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
Env::load(dirname($root, 2) . '/.env');
Env::load(dirname($root) . '/.env');
$pdo = Db::connect();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$dir = null; $prodFile = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--prodawards=(.+)$/', $a, $mm)) $prodFile = $mm[1];   // union in awards from a file (one id/line)
    elseif ($a !== '' && $a[0] !== '-') $dir = $a;
}
$dir  = $dir ?? (dirname($root) . '/references/All_D2_Assistance_ZIP');
$zips = glob(rtrim($dir, "/\\") . '/*.zip');
if (!$zips) { fwrite(STDERR, "no .zip files in $dir\n"); exit(1); }
sort($zips);
echo count($zips) . " D2 zips in $dir:\n"; foreach ($zips as $z) echo '  ' . basename($z) . "\n";

// 1) our award_ids -> in-memory set for O(1) filtering
fwrite(STDERR, "loading our award_ids... ");
$ours = [];
$q = $pdo->query("SELECT award_id FROM usa_award");
while (($id = $q->fetchColumn()) !== false) $ours[$id] = true;
fwrite(STDERR, number_format(count($ours)) . " awards\n");
// optional: also keep PROD-only awards whose ids aren't in local usa_award (local's award sync lags
// prod), so their D2 txns aren't filtered out — pass --prodawards=<file, one award_id per line>.
if ($prodFile !== null && is_file($prodFile)) {
    fwrite(STDERR, "unioning prod award_ids from $prodFile... ");
    $added = 0; $ph = fopen($prodFile, 'r');
    while (($line = fgets($ph)) !== false) { $id = rtrim($line, "\r\n"); if ($id !== '' && !isset($ours[$id])) { $ours[$id] = true; $added++; } }
    fclose($ph);
    fwrite(STDERR, '+' . number_format($added) . ' prod-only (union ' . number_format(count($ours)) . ")\n");
}

// 2) fresh staging table (raw, one row per kept transaction)
$pdo->exec("DROP TABLE IF EXISTS d2_txn_stage");
$pdo->exec("CREATE TABLE d2_txn_stage (award_id VARCHAR(64) NOT NULL, cfda VARCHAR(24) NOT NULL DEFAULT '',
            ym DATE NOT NULL, obligation DECIMAL(18,2) NOT NULL) ENGINE=InnoDB");
$CHUNK = 2000;
$insFull = $pdo->prepare("INSERT INTO d2_txn_stage (award_id,cfda,ym,obligation) VALUES "
    . implode(',', array_fill(0, $CHUNK, '(?,?,?,?)')));

// 3) stream each zip's CSVs, filter to our awards, stage
$grand = 0; $keptAll = 0; $t0 = time();
foreach ($zips as $zip) {
    $za = new ZipArchive();
    if ($za->open($zip) !== true) { fwrite(STDERR, "cannot open $zip\n"); continue; }
    for ($i = 0; $i < $za->numFiles; $i++) {
        $entry = $za->getNameIndex($i);
        if (!preg_match('/\.csv$/i', $entry)) continue;
        $fh = fopen('zip://' . $zip . '#' . $entry, 'r');
        if (!$fh) { fwrite(STDERR, "cannot stream $entry\n"); continue; }
        $hdr = fgetcsv($fh);
        if (!$hdr) { fclose($fh); continue; }
        $ix = array_flip($hdr);
        $need = ['assistance_award_unique_key','federal_action_obligation','action_date','cfda_number'];
        $miss = array_diff($need, array_keys($ix));
        if ($miss) { fwrite(STDERR, "  SKIP $entry (missing: " . implode(',', $miss) . ")\n"); fclose($fh); continue; }
        $cA = $ix['assistance_award_unique_key']; $cO = $ix['federal_action_obligation'];
        $cD = $ix['action_date']; $cC = $ix['cfda_number'];
        $buf = []; $n = 0; $rows = 0; $kept = 0;
        while (($r = fgetcsv($fh)) !== false) {
            $rows++;
            $aid = $r[$cA] ?? '';
            if ($aid === '' || !isset($ours[$aid])) continue;
            $d = $r[$cD] ?? '';
            if (strlen($d) < 7) continue;
            $buf[] = $aid; $buf[] = (string) ($r[$cC] ?? ''); $buf[] = substr($d, 0, 7) . '-01'; $buf[] = (float) ($r[$cO] ?? 0);
            $kept++;
            if (++$n === $CHUNK) { $insFull->execute($buf); $buf = []; $n = 0; }
        }
        if ($buf) {
            $pdo->prepare("INSERT INTO d2_txn_stage (award_id,cfda,ym,obligation) VALUES "
                . implode(',', array_fill(0, count($buf) / 4, '(?,?,?,?)')))->execute($buf);
        }
        fclose($fh);
        $grand += $rows; $keptAll += $kept;
        printf("  %s : %s rows, kept %s  [%ds]\n", $entry, number_format($rows), number_format($kept), time() - $t0);
    }
    $za->close();
}
echo 'streamed ' . number_format($grand) . ' rows; kept ' . number_format($keptAll)
    . ' for our awards  [' . (time() - $t0) . "s]\n";

// 4) aggregate stage -> fresh txn_month table
echo "indexing + aggregating...\n";
$pdo->exec("ALTER TABLE d2_txn_stage ADD INDEX idx_agg (award_id, cfda, ym)");
$pdo->exec("DROP TABLE IF EXISTS usa_award_txn_month_new");
$pdo->exec("CREATE TABLE usa_award_txn_month_new LIKE usa_award_txn_month");
$pdo->exec("INSERT INTO usa_award_txn_month_new (award_id,cfda,ym,obligation)
            SELECT award_id, cfda, ym, SUM(obligation) FROM d2_txn_stage GROUP BY award_id, cfda, ym");
$newRows = (int) $pdo->query("SELECT COUNT(*) FROM usa_award_txn_month_new")->fetchColumn();
$newAwd  = (int) $pdo->query("SELECT COUNT(DISTINCT award_id) FROM usa_award_txn_month_new")->fetchColumn();
$oldAwd  = (int) $pdo->query("SELECT COUNT(DISTINCT award_id) FROM usa_award_txn_month")->fetchColumn();
echo 'NEW: ' . number_format($newRows) . ' rows, ' . number_format($newAwd) . ' awards  (was '
    . number_format($oldAwd) . " awards)\n";

// 5) sanity gate, then atomic swap (old kept for rollback)
if ($newAwd < $oldAwd * 0.9) {
    fwrite(STDERR, "ABORT swap: new coverage ($newAwd awards) < 90% of old ($oldAwd). "
        . "Left as usa_award_txn_month_new for inspection.\n");
    exit(1);
}
$pdo->exec("DROP TABLE IF EXISTS usa_award_txn_month_old");
$pdo->exec("RENAME TABLE usa_award_txn_month TO usa_award_txn_month_old, usa_award_txn_month_new TO usa_award_txn_month");
// Intentionally do NOT re-add an FK to usa_award: an ON DELETE CASCADE here let sync_usa.php's
// per-recipient DELETE+reinsert wipe obligation months nightly (see 2026-07-10_txn_month_drop_fk.sql,
// mirroring the outlay-month fix). CREATE TABLE ... LIKE does not copy FKs, so the swapped-in table
// is already FK-free — every reader joins through usa_award, so orphaned month rows are harmless.
echo "SWAPPED. Prior data kept as usa_award_txn_month_old (rollback); raw stage kept as d2_txn_stage.\n";
echo "DONE  [" . (time() - $t0) . "s]\n";
