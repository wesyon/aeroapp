<?php
declare(strict_types=1);

/**
 * AERO — one-off backfill: recompute fac_findings boolean flags as a boolean OR
 * across the per-award rows FAC delivers, repairing the historical last-write-wins
 * collapse.
 *
 * Why this exists: FAC's `findings` feed is grained per (finding x award), but
 * fac_findings is keyed (report_id, reference_number). A finding spanning several
 * federal awards arrives as one row per award, and a Y/N flag (modified opinion,
 * material weakness, ...) is Y only on the award it applies to and N on the
 * co-listed ones. The importer historically overwrote the row per award (the
 * Upserter ON DUPLICATE KEY UPDATE), so the stored flag was whichever award row
 * landed last — silently dropping the Y and undercounting Eval L2/L3. sync_fac.php
 * now ORs these flags going forward (Upserter $orCols); this script corrects the
 * rows that were already loaded.
 *
 * It re-fetches ONLY the flag columns from FAC, ORs them per finding, and UPDATEs
 * fac_findings in place — no DELETE, so the findings-text / extract / CAP children
 * are untouched (unlike a findings re-sync, which cascades them away). Idempotent:
 * re-running converges to the same flags. Quota-safe on prod (UPDATEs only, no new
 * rows) — but a finding absent locally/on-prod simply isn't updated.
 *
 * Usage:
 *   php repair_finding_flags.php              # all years (2022..present)
 *   php repair_finding_flags.php --years=2024,2025
 *   php repair_finding_flags.php --dry-run    # report what would change, write nothing
 */

$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
require $root . '/lib/Http.php';
require $root . '/lib/FacClient.php';
require $root . '/lib/Normalize.php';
Env::load(dirname($root, 2) . '/.env');
Env::load(dirname($root) . '/.env');

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$years = isset($args['years'])
    ? array_map('trim', explode(',', (string) $args['years']))
    : array_map('strval', range(2022, (int) date('Y')));
$dryRun = isset($args['dry-run']);

// The seven Y/N finding flags that collapse per (finding x award). Must match the
// 'yn' columns of $SPEC_FINDINGS in sync_fac.php (Upserter $orCols).
$FLAGS = ['is_material_weakness', 'is_significant_deficiency', 'is_modified_opinion',
          'is_other_findings', 'is_other_matters', 'is_questioned_costs', 'is_repeat_finding'];

$pdo = Db::connect();
$fac = new FacClient(Env::require('FAC_BASE_URL'), Env::require('FAC_API_KEY'));

echo "Repair fac_findings flags via boolean-OR rollup" . ($dryRun ? " (DRY RUN)" : "")
   . " — years " . implode(',', $years) . "\n";

// 1) Fetch the per-award flag rows from FAC and OR them per (report_id, reference_number).
//    Only the key + the seven flags are selected, so the payload is a fraction of a
//    full findings pull. NULL (unknown) never beats a 0/1; an all-NULL flag stays NULL.
$want   = array_merge(['report_id', 'reference_number'], $FLAGS);
$filter = 'audit_year=in.(' . implode(',', array_map('intval', $years)) . ')'
        . '&select=' . implode(',', $want);
$acc = [];   // "report_id|reference_number" => [flag => 0|1|null]
$pages = 0;
$awardRows = $fac->each('findings', $filter, function (array $page) use (&$acc, &$pages, $FLAGS) {
    foreach ($page as $r) {
        $k = ($r['report_id'] ?? '') . '|' . ($r['reference_number'] ?? '');
        if (!isset($acc[$k])) $acc[$k] = array_fill_keys($FLAGS, null);
        foreach ($FLAGS as $f) {
            $v = n_yn($r[$f] ?? null);
            if ($v === null) continue;                 // unknown: don't lower an existing value
            $acc[$k][$f] = max((int) ($acc[$k][$f] ?? 0), $v);   // boolean OR
        }
    }
    if (++$pages % 50 === 0) fprintf(STDERR, "  ...%d pages, %d findings so far\n", $pages, count($acc));
});
echo "Fetched $awardRows award-grain rows -> " . count($acc) . " distinct findings.\n";

// 2) Apply: UPDATE each finding's flags to the OR-rolled values, only where the row
//    exists AND a flag actually differs (rowCount reflects real changes). Wrapped in
//    one transaction for speed; the WHERE-any-differs guard keeps it idempotent.
$set = implode(', ', array_map(fn ($f) => "`$f` = :$f", $FLAGS));
$diff = implode(' OR ', array_map(fn ($f) => "NOT (`$f` <=> :w_$f)", $FLAGS));   // <=> = NULL-safe equality
$upd = $pdo->prepare(
    "UPDATE fac_findings SET $set
      WHERE report_id = :rid AND reference_number = :ref AND ($diff)"
);

$changed = 0; $applied = 0;
$pdo->beginTransaction();
foreach ($acc as $k => $flags) {
    [$rid, $ref] = explode('|', $k, 2);
    $params = [':rid' => $rid, ':ref' => $ref];
    foreach ($FLAGS as $f) { $params[":$f"] = $flags[$f]; $params[":w_$f"] = $flags[$f]; }
    if ($dryRun) {
        // count would-change rows without writing
        $chk = $pdo->prepare("SELECT 1 FROM fac_findings WHERE report_id=:rid AND reference_number=:ref AND ($diff) LIMIT 1");
        $p2 = [':rid' => $rid, ':ref' => $ref];
        foreach ($FLAGS as $f) $p2[":w_$f"] = $flags[$f];
        $chk->execute($p2);
        if ($chk->fetchColumn()) $changed++;
        continue;
    }
    $upd->execute($params);
    if ($upd->rowCount() > 0) { $changed++; $applied += $upd->rowCount(); }
}
if ($dryRun) { $pdo->rollBack(); } else { $pdo->commit(); }

echo ($dryRun ? "Would change" : "Changed") . " $changed findings"
   . ($dryRun ? "" : " ($applied rows updated).") . "\n";
echo "Done.\n";
