<?php
declare(strict_types=1);

/**
 * AERO — Layer 0 report: how much of our USAspending money evidence is attributable to the entity
 * we think it belongs to? Read-only; writes nothing. Run before trusting any dollar-based
 * delinquency/exposure work.
 *
 *   php api/sync/report_money_trust.php            # summary
 *   php api/sync/report_money_trust.php --list     # + every quarantined UEI
 *
 * See api/lib/MoneyTrust.php for the two checks and how their thresholds were calibrated.
 */

$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
require $root . '/lib/MoneyTrust.php';
Env::load(dirname($root, 2) . '/.env');
Env::load(dirname($root) . '/.env');
$pdo = Db::connect();
$list = in_array('--list', $argv, true);

$t0 = microtime(true);
$trust = aero_money_trust($pdo);
$n = count($trust);
if (!$n) { fwrite(STDERR, "no entities with both a FAC identity and USAspending awards\n"); exit(1); }

$by = ['ok' => 0, 'suspect_name' => 0, 'suspect_scale' => 0, 'suspect_both' => 0];
$oblBy = ['ok' => 0.0, 'suspect_name' => 0.0, 'suspect_scale' => 0.0, 'suspect_both' => 0.0];
foreach ($trust as $t) { $by[$t['verdict']]++; $oblBy[$t['verdict']] += $t['obligations']; }
$bad = $n - $by['ok'];
$oblAll = array_sum($oblBy);

echo "=== LAYER 0 — USAspending money attribution ===\n";
printf("entities with a FAC identity AND USAspending awards: %s\n\n", number_format($n));
printf("  %-14s %7s  %6s   obligations\n", 'VERDICT', 'ENTITIES', '');
foreach ($by as $k => $v) {
    printf("  %-14s %7s  %5.1f%%   $%s\n", $k, number_format($v), 100 * $v / $n,
        number_format($oblBy[$k] / 1e9, 1) . 'B');
}
printf("\n  QUARANTINED (money unusable as evidence about that entity): %s (%.2f%%), $%s of obligations (%.1f%% of all)\n",
    number_format($bad), 100 * $bad / $n, number_format(($oblAll - $oblBy['ok']) / 1e9, 1) . 'B',
    $oblAll > 0 ? 100 * ($oblAll - $oblBy['ok']) / $oblAll : 0);

// What it means for the delinquency work: L1 entities whose money evidence we must not trust.
try {
    $l1 = [];
    foreach ($pdo->query("SELECT uei FROM signal_flag WHERE code = 'MISSING_AUDIT_THRESHOLD'") as $r) $l1[$r['uei']] = true;
    $hit = 0; $hitObl = 0.0;
    foreach ($l1 as $uei => $_x) {
        if (isset($trust[$uei]) && $trust[$uei]['verdict'] !== 'ok') { $hit++; $hitObl += $trust[$uei]['obligations']; }
    }
    printf("\n  of %s Level-1 entities, %s have quarantined money evidence ($%s of obligations)\n",
        number_format(count($l1)), number_format($hit), number_format($hitObl / 1e9, 1) . 'B');
} catch (\Throwable $e) { echo "\n  (signal_flag not built — skipping the Level-1 cross-check)\n"; }

// The worst offenders are the ones that would top a dollar-ranked lead list.
$rows = array_filter($trust, static fn ($t) => $t['verdict'] !== 'ok');
uasort($rows, static fn ($a, $b) => $b['obligations'] <=> $a['obligations']);
$show = $list ? $rows : array_slice($rows, 0, 12, true);
printf("\n%s quarantined UEIs by obligations at stake:\n", $list ? 'ALL' : 'TOP 12');
printf("  %-13s %-13s %10s  %-30s %s\n", 'UEI', 'VERDICT', 'OBLIGATIONS', 'FAC SAYS', 'USASPENDING SAYS');
foreach ($show as $uei => $t) {
    printf("  %-13s %-13s %10s  %-30s %s\n", $uei, $t['verdict'],
        '$' . number_format($t['obligations'] / 1e6, 0) . 'M',
        substr($t['fac_name'], 0, 30), substr($t['usa_name'], 0, 34));
}
printf("\n%.1fs\n", microtime(true) - $t0);
