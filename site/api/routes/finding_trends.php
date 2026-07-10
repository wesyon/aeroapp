<?php
declare(strict_types=1);

/**
 * GET /api/finding_trends?uei=XXXX               — one entity's findings-by-attribute per audit year
 * GET /api/finding_trends?type=local[&state=OK]  — the whole scope's aggregate (Evaluation dashboard
 *                                                  idle state: type = entity_type, or stategov/territory
 *                                                  for the state_uei registry)
 * Same series the profile Overview's "Findings Trend by Type" chart uses, served light for the
 * Evaluation dashboard. Active reports only, so resubmissions don't double-count.
 */

$uei   = q_str('uei');
$type  = q_str('type');
$state = q_str('state');

$TERR = ['PR', 'VI', 'GU', 'AS', 'MP'];

$select = "SELECT f.audit_year yr,
                  SUM(f.is_material_weakness=1) mw, SUM(f.is_significant_deficiency=1) sd,
                  SUM(f.is_questioned_costs=1) qc, SUM(f.is_modified_opinion=1) modified,
                  SUM(f.is_repeat_finding=1) repeat_n, COUNT(*) total
           FROM fac_findings f
           JOIN fac_general g ON g.report_id = f.report_id AND g.is_active = 1";

if ($uei !== null) {
    if (!preg_match('/^[A-Za-z0-9]{12}$/', $uei)) json_out(['error' => 'a valid 12-char uei is required'], 400);
    $st = $pdo->prepare("$select WHERE g.auditee_uei = ? GROUP BY f.audit_year ORDER BY f.audit_year");
    $st->execute([$uei]);
} elseif ($type === 'stategov' || $type === 'territory') {
    // registry scopes: every UEI in the state_uei crosswalk (succession groups), optionally one state
    $ueis = [];
    foreach ($pdo->query('SELECT state_code, ueis FROM state_uei') as $r) {
        $isTerr = in_array($r['state_code'], $TERR, true);
        if (($type === 'territory') !== $isTerr && $type === 'territory') continue;
        if ($state !== null && $r['state_code'] !== $state) continue;
        foreach (preg_split('/\R+/', (string) $r['ueis']) ?: [] as $u) {
            if (($u = trim($u)) !== '') $ueis[] = $u;
        }
    }
    if (!$ueis) json_out(['trends' => []]);
    $in = implode(',', array_fill(0, count($ueis), '?'));
    $st = $pdo->prepare("$select WHERE g.auditee_uei IN ($in) GROUP BY f.audit_year ORDER BY f.audit_year");
    $st->execute($ueis);
} elseif ($type !== null) {
    // entity cohorts: aggregate the whole type (optionally one state) via the entity hub
    $sql = "$select JOIN entity e ON e.uei = g.auditee_uei WHERE e.entity_type = ?";
    $args = [$type];
    if ($state !== null) { $sql .= ' AND e.state = ?'; $args[] = $state; }
    $st = $pdo->prepare("$sql GROUP BY f.audit_year ORDER BY f.audit_year");
    $st->execute($args);
} else {
    json_out(['error' => 'uei or type is required'], 400);
}

$trends = array_map(fn ($r) => [
    'year'     => (int) $r['yr'],
    'mw'       => (int) $r['mw'],
    'sd'       => (int) $r['sd'],
    'qc'       => (int) $r['qc'],
    'modified' => (int) $r['modified'],
    'repeat'   => (int) $r['repeat_n'],
    'total'    => (int) $r['total'],
], $st->fetchAll());

json_out(['trends' => $trends]);
