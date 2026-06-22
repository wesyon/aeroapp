<?php
declare(strict_types=1);
/**
 * GET /api/dashboard[?hhs=1] — national portfolio rollups for the Dashboard page.
 * Most slices come from aero_score (one row per recipient, indexed); findings/QC come
 * from fac_findings / fac_finding_extract. ?hhs=1 scopes everything to HHS-linked
 * recipients (aero_score.is_hhs). Results are cached to disk per scope.
 */

$hhsOnly = isset($_GET['hhs']) && $_GET['hhs'];

// Cache per scope; recompute with ?fresh=1 (local console only — the recompute runs
// every heavy aggregate, so we don't let arbitrary callers bust the cache in prod).
$cacheFile = dirname(__DIR__) . '/cache/dashboard' . ($hhsOnly ? '_hhs' : '') . '.json';
$fresh = isset($_GET['fresh']) && is_local_request();
if (!$fresh && is_file($cacheFile) && (time() - filemtime($cacheFile)) < 21600) {
    json_out(json_decode((string) file_get_contents($cacheFile), true));
}

$QC_TRUSTED = "'known','generic','flagged','inline'";
$sWhere = $hhsOnly ? 'WHERE is_hhs = 1' : '';                                           // aero_score, no other WHERE
$sAnd   = $hhsOnly ? ' AND is_hhs = 1' : '';                                            // append to an existing WHERE
$fJoin  = $hhsOnly ? 'JOIN aero_score sh ON sh.uei = f.auditee_uei AND sh.is_hhs = 1' : ''; // findings → HHS recipients
$xJoin  = $hhsOnly ? 'JOIN aero_score sx ON sx.uei = x.auditee_uei AND sx.is_hhs = 1' : '';
$out = [];

// --- risk tiers + portfolio totals (one pass over aero_score) ---
$tiers = []; $recipients = 0; $federal = 0; $hhs = 0; $highRisk = 0;
$HIGH = ['Elevated', 'Substantial', 'Severe'];
foreach ($pdo->query("SELECT tier, COUNT(*) n, COALESCE(SUM(federal_latest),0) fed, COALESCE(SUM(is_hhs),0) hhs FROM aero_score $sWhere GROUP BY tier") as $r) {
    $tiers[$r['tier']] = ['tier' => $r['tier'], 'n' => (int) $r['n'], 'fed' => (float) $r['fed']];
    $recipients += (int) $r['n'];
    $federal += (float) $r['fed'];
    $hhs += (int) $r['hhs'];
    if (in_array($r['tier'], $HIGH, true)) $highRisk += (int) $r['n'];
}
$ORDER = ['Severe', 'Substantial', 'Elevated', 'Moderate', 'Minimal', 'Clean'];
$out['tiers'] = array_values(array_filter(array_map(fn ($t) => $tiers[$t] ?? null, $ORDER)));

// --- risk posture index: composite-score stats over Minimal+ vs Moderate+ ---
$posture = function (string $where) use ($pdo) {
    $a = $pdo->query("SELECT COUNT(*) n, AVG(composite_score) mean, MAX(composite_score) max FROM aero_score $where")->fetch();
    $n = (int) $a['n'];
    $pick = function ($frac) use ($pdo, $where, $n) {
        if ($n === 0) return 0.0;
        $off = max(0, (int) floor($n * $frac) - 1);
        return (float) $pdo->query("SELECT composite_score FROM aero_score $where ORDER BY composite_score LIMIT 1 OFFSET $off")->fetchColumn();
    };
    return ['n' => $n, 'mean' => round((float) $a['mean'], 2), 'max' => round((float) $a['max'], 1),
            'median' => round($pick(0.5), 1), 'p90' => round($pick(0.9), 1)];
};
$out['posture'] = [
    'minimal'  => $posture('WHERE composite_score >= 1' . $sAnd),    // excludes Clean (score 0)
    'moderate' => $posture('WHERE composite_score >= 20' . $sAnd),   // Moderate tier starts at 20
];

// --- average component sub-scores ---
$c = $pdo->query("SELECT AVG(sc_internal_control) ic, AVG(sc_repeat_findings) rf, AVG(sc_questioned_costs) qc,
                         AVG(sc_reporting_timeliness) rt, AVG(sc_cash_financial) cf, AVG(sc_subrecipient) sub,
                         AVG(sc_cap_quality) cap FROM aero_score $sWhere")->fetch();
$out['components'] = [
    ['label' => 'Internal control', 'weight' => 25, 'avg' => round((float) $c['ic'], 1)],
    ['label' => 'Repeat findings', 'weight' => 20, 'avg' => round((float) $c['rf'], 1)],
    ['label' => 'Questioned costs', 'weight' => 15, 'avg' => round((float) $c['qc'], 1)],
    ['label' => 'Reporting timeliness', 'weight' => 15, 'avg' => round((float) $c['rt'], 1)],
    ['label' => 'Cash / financial', 'weight' => 10, 'avg' => round((float) $c['cf'], 1)],
    ['label' => 'Subrecipient', 'weight' => 10, 'avg' => round((float) $c['sub'], 1)],
    ['label' => 'CAP quality', 'weight' => 5, 'avg' => round((float) $c['cap'], 1)],
];

// --- highest-risk recipients ---
$out['top_risk'] = array_map(fn ($r) => [
    'uei' => $r['uei'], 'name' => $r['recipient_name'], 'state' => $r['state'],
    'tier' => $r['tier'], 'score' => (float) $r['composite_score'], 'federal' => (float) $r['federal_latest'],
], $pdo->query("SELECT uei, recipient_name, state, tier, composite_score, federal_latest
                FROM aero_score $sWhere ORDER BY composite_score DESC, federal_latest DESC LIMIT 15")->fetchAll());

// --- risk by state ---
$out['by_state'] = array_map(fn ($r) => [
    'state' => $r['state'], 'n' => (int) $r['n'], 'avg' => round((float) $r['avg'], 1),
    'high' => (int) $r['high'], 'fed' => (float) $r['fed'],
], $pdo->query("SELECT state, COUNT(*) n, AVG(composite_score) avg,
                       SUM(tier IN ('Elevated','Substantial','Severe')) high, COALESCE(SUM(federal_latest),0) fed
                FROM aero_score WHERE state IS NOT NULL AND state <> ''$sAnd GROUP BY state")->fetchAll());

// --- entity-type mix ---
$out['entity_types'] = array_map(fn ($r) => ['type' => $r['t'] ?: 'unknown', 'n' => (int) $r['n']],
    $pdo->query("SELECT COALESCE(NULLIF(entity_type,''),'unknown') t, COUNT(*) n FROM aero_score $sWhere GROUP BY t ORDER BY n DESC LIMIT 8")->fetchAll());

// --- findings by audit year ---
$findingsTotal = 0;
$out['findings_by_year'] = array_map(function ($r) use (&$findingsTotal) {
    $findingsTotal += (int) $r['total'];
    return ['year' => (int) $r['yr'], 'mw' => (int) $r['mw'], 'repeat' => (int) $r['repeat_n'],
            'qc' => (int) $r['qc'], 'modified' => (int) $r['mo'], 'total' => (int) $r['total']];
}, $pdo->query("SELECT f.audit_year yr, SUM(f.is_material_weakness=1) mw, SUM(f.is_repeat_finding=1) repeat_n,
                       SUM(f.is_questioned_costs=1) qc, SUM(f.is_modified_opinion=1) mo, COUNT(*) total
                FROM fac_findings f
                JOIN fac_general ga ON ga.report_id = f.report_id AND ga.is_active = 1
                $fJoin WHERE f.audit_year >= 2016 GROUP BY f.audit_year ORDER BY f.audit_year")->fetchAll());

// --- findings by compliance-requirement type (split multi-letter codes per letter) ---
$REQ = ['A' => 'Activities Allowed', 'B' => 'Allowable Costs', 'C' => 'Cash Management', 'D' => 'Davis-Bacon',
    'E' => 'Eligibility', 'F' => 'Equipment', 'G' => 'Matching', 'H' => 'Period of Performance',
    'I' => 'Procurement', 'J' => 'Program Income', 'K' => 'Real Property', 'L' => 'Reporting',
    'M' => 'Subrecipient Monitoring', 'N' => 'Special Tests', 'P' => 'Other'];
$byLetter = [];
foreach ($pdo->query("SELECT f.type_requirement t, COUNT(*) n FROM fac_findings f
                      JOIN fac_general ga ON ga.report_id = f.report_id AND ga.is_active = 1 $fJoin
                      WHERE f.type_requirement IS NOT NULL AND f.type_requirement <> '' GROUP BY f.type_requirement") as $r) {
    foreach (str_split(strtoupper((string) $r['t'])) as $ch) {
        if (!ctype_alpha($ch)) continue;
        $byLetter[$ch] = ($byLetter[$ch] ?? 0) + (int) $r['n'];
    }
}
arsort($byLetter);
$out['finding_types'] = [];
foreach ($byLetter as $ch => $n) $out['finding_types'][] = ['type' => $ch, 'label' => $REQ[$ch] ?? $ch, 'n' => $n];

// --- questioned costs: total + leaderboard ---
$qcTot = $pdo->query("SELECT COALESCE(SUM(x.qc_amount),0) total, COUNT(*) n FROM fac_finding_extract x
                      JOIN fac_general ga ON ga.report_id = x.report_id AND ga.is_active = 1 $xJoin
                      WHERE x.qc_amount > 0 AND x.qc_basis IN ($QC_TRUSTED)")->fetch();
$qcTopAnd = $hhsOnly ? ' AND s.is_hhs = 1' : '';
$out['qc'] = [
    'total' => (float) $qcTot['total'], 'count' => (int) $qcTot['n'],
    'top' => array_map(fn ($r) => [
        'uei' => $r['uei'], 'name' => $r['name'], 'state' => $r['state'],
        'qc' => (float) $r['qc'], 'findings' => (int) $r['findings'],
    ], $pdo->query("SELECT x.auditee_uei uei, MAX(s.recipient_name) name, MAX(s.state) state,
                           SUM(x.qc_amount) qc, COUNT(*) findings
                    FROM fac_finding_extract x JOIN aero_score s ON s.uei = x.auditee_uei
                    JOIN fac_general ga ON ga.report_id = x.report_id AND ga.is_active = 1
                    WHERE x.qc_amount > 0 AND x.qc_basis IN ($QC_TRUSTED)$qcTopAnd
                    GROUP BY x.auditee_uei ORDER BY qc DESC LIMIT 12")->fetchAll()),
];

$out['kpis'] = [
    'recipients' => $recipients, 'high_risk' => $highRisk, 'hhs' => $hhs,
    'federal' => $federal, 'findings' => $findingsTotal, 'qc_total' => (float) $qcTot['total'],
];
$out['scope'] = $hhsOnly ? 'hhs' : 'all';
$out['generated_at'] = date('c');

@mkdir(dirname($cacheFile), 0775, true);
@file_put_contents($cacheFile, json_encode($out));
json_out($out);
