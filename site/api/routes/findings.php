<?php
declare(strict_types=1);

/**
 * GET /api/findings — AUDIT FINDINGS dashboard.
 *
 * One row per finding on an ACTIVE Single Audit (fac_findings x fac_general.is_active).
 * Severity flags are not mutually exclusive — one finding can be a material weakness AND
 * questioned costs AND a repeat — so the chips filter (?flag=) narrows to one flag at a
 * time rather than partitioning the population the way the opinions categories do.
 *
 * Questioned-cost dollars come from fac_finding_extract.qc_amount (the parse_findings
 * extraction) via a covering index — see
 * migrations/2026-07-16_fac_finding_extract_join_index.sql. FORCE INDEX is deliberate:
 * the optimizer otherwise prefers eq_ref on the PRIMARY key, dragging the MEDIUMTEXT row
 * for every one of ~57k findings (~8s; covered ~0.9s).
 *
 * ?hhs=1 narrows to findings tied to at least one HHS award (fac_finding_awards bridge,
 * federal_agency_prefix 93). No pushed table — prod computes on its own HHS-pruned scope.
 *
 *   ?action=summary   totals + per-flag counts + extracted QC $ + facets (respects filters)
 *   ?action=leads     &flag=mw|sd|qc|mo|repeat &fy &etype &state &q &hhs &sort &dir &limit &offset
 */

require_once dirname(__DIR__) . '/lib/CohortFilter.php';

$action = q_str('action') ?? 'summary';

// flag key -> column (chip filter + summary counters)
const FIND_FLAGS = [
    'mw'     => 'f.is_material_weakness',
    'sd'     => 'f.is_significant_deficiency',
    'qc'     => 'f.is_questioned_costs',
    'mo'     => 'f.is_modified_opinion',
    'repeat' => 'f.is_repeat_finding',
];

$TABLES = 'FROM fac_findings f JOIN fac_general g ON g.report_id = f.report_id
           LEFT JOIN entity e ON e.uei = f.auditee_uei
           LEFT JOIN fac_finding_extract x FORCE INDEX (idx_fext_join_qc)
             ON x.report_id = f.report_id AND x.finding_ref_number = f.reference_number';

$REGISTRY = aero_registry_ueis($pdo);

// shared filters (page-wide, mirroring the delinquency/opinions dashboards)
$fyS = q_str('fy');
$fy = ($fyS !== null && preg_match('/^\d{4}$/', $fyS)) ? (int) $fyS : null;
$etype = q_str('etype');
$etype = ($etype !== null && preg_match('/^[a-z-]{2,20}$/', $etype)) ? $etype : null;
$stateF = strtoupper((string) (q_str('state') ?? ''));
$q = trim((string) (q_str('q') ?? ''));
$flag = q_str('flag');
$flag = isset(FIND_FLAGS[$flag]) ? $flag : null;
$hhs = q_str('hhs') === '1';

$conds = ['g.is_active = 1'];
$params = [];
if ($etype !== null) { aero_etype_cond($etype, $REGISTRY, 'f.auditee_uei', 'e.entity_type', $conds, $params); }
if (preg_match('/^[A-Z]{2}$/', $stateF)) { $conds[] = 'COALESCE(e.state, g.auditee_state) = ?'; $params[] = $stateF; }
if (mb_strlen($q) >= 2) {
    $conds[] = '(COALESCE(e.display_name, g.auditee_name) LIKE ? OR f.auditee_uei LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($hhs) {
    // tied to at least one HHS award — rides idx_fawards_prefix_finding / the bridge PK
    $conds[] = "EXISTS (SELECT 1 FROM fac_finding_awards b WHERE b.report_id = f.report_id
                AND b.reference_number = f.reference_number AND b.federal_agency_prefix = '93')";
}
if ($flag !== null) { $conds[] = FIND_FLAGS[$flag] . ' = 1'; }
if ($fy !== null) { $conds[] = 'g.audit_year = ?'; $params[] = $fy; }
$FROM = "$TABLES WHERE " . implode(' AND ', $conds);

if ($action === 'summary') {
    $flagSums = implode(', ', array_map(
        static fn ($k, $col) => "SUM($col) `$k`",   // backticks: 'repeat' is a reserved word
        array_keys(FIND_FLAGS), FIND_FLAGS));
    $st = $pdo->prepare("SELECT COUNT(*) n, COUNT(DISTINCT f.auditee_uei) entities,
                COUNT(DISTINCT f.report_id) audits, $flagSums,
                SUM(x.qc_amount > 0) qc_extracted,
                SUM(CASE WHEN x.qc_amount > 0 THEN x.qc_amount ELSE 0 END) qc_dollars
         $FROM");
    $st->execute($params);
    $r = $st->fetch();

    // facets ride fac_findings' own denormalized columns (idx_findings_year / idx_findings_uei)
    // instead of the g join — a year or state that only exists on inactive reports may appear,
    // which is harmless in a dropdown and keeps these instant.
    $types = $pdo->query("SELECT DISTINCT e.entity_type
                          FROM (SELECT DISTINCT auditee_uei FROM fac_findings) u
                          JOIN entity e ON e.uei = u.auditee_uei
                          WHERE e.entity_type IS NOT NULL ORDER BY e.entity_type")->fetchAll(PDO::FETCH_COLUMN);
    if ($REGISTRY) {
        $in = implode(',', array_fill(0, count($REGISTRY), '?'));
        $st = $pdo->prepare("SELECT 1 FROM fac_findings WHERE auditee_uei IN ($in) LIMIT 1");
        $st->execute($REGISTRY);
        if ($st->fetchColumn()) array_unshift($types, 'stategov');
    }
    json_out([
        'mode' => 'findings',
        'totals' => ['findings' => (int) $r['n'], 'entities' => (int) $r['entities'],
                     'audits' => (int) $r['audits']],
        'by_flag' => array_combine(array_keys(FIND_FLAGS),
                       array_map(static fn ($k) => (int) $r[$k], array_keys(FIND_FLAGS))),
        'qc_extracted' => (int) $r['qc_extracted'],
        'qc_dollars' => (float) $r['qc_dollars'],
        'fys' => array_map('intval', $pdo->query('SELECT DISTINCT audit_year FROM fac_findings
                    WHERE audit_year IS NOT NULL ORDER BY audit_year')->fetchAll(PDO::FETCH_COLUMN)),
        'types' => $types,
        'states' => $pdo->query("SELECT DISTINCT e.state
                    FROM (SELECT DISTINCT auditee_uei FROM fac_findings) u
                    JOIN entity e ON e.uei = u.auditee_uei
                    WHERE e.state > '' ORDER BY e.state")->fetchAll(PDO::FETCH_COLUMN),
        'generated_at' => date('c'),
    ]);
}

if ($action === 'leads') {
    $limit = q_int('limit', 100, 1, 25000);   // paging uses 100; CSV export pulls up to the cap
    $offset = q_int('offset', 0, 0, 1000000);
    $SORTS = [
        'qc' => 'x.qc_amount',
        'name' => 'COALESCE(e.display_name, g.auditee_name)',
        'state' => "COALESCE(e.state, g.auditee_state, 'ZZ')",
        'fy' => 'g.audit_year',
        'req' => "COALESCE(f.type_requirement, '~')",
    ];
    $sortCol = $SORTS[q_str('sort') ?? 'qc'] ?? 'x.qc_amount';
    $sortDir = q_str('dir') === 'asc' ? 'ASC' : 'DESC';

    $ct = $pdo->prepare("SELECT COUNT(*) $FROM");
    $ct->execute($params);
    $total = (int) $ct->fetchColumn();

    $st = $pdo->prepare("SELECT f.auditee_uei uei, COALESCE(e.display_name, g.auditee_name) name,
                COALESCE(e.state, g.auditee_state) state, g.audit_year fy,
                f.report_id, f.reference_number ref, f.type_requirement req,
                f.is_material_weakness mw, f.is_significant_deficiency sd, f.is_questioned_costs qc,
                f.is_modified_opinion mo, f.is_repeat_finding rpt, x.qc_amount
         $FROM ORDER BY $sortCol $sortDir, f.report_id, f.reference_number LIMIT $limit OFFSET $offset");
    $st->execute($params);
    $rows = array_map(static fn ($r) => [
        'uei' => $r['uei'], 'name' => $r['name'], 'state' => $r['state'], 'fy' => (int) $r['fy'],
        'report_id' => $r['report_id'], 'ref' => $r['ref'], 'req' => $r['req'],
        'mw' => (bool) $r['mw'], 'sd' => (bool) $r['sd'], 'qc' => (bool) $r['qc'],
        'mo' => (bool) $r['mo'], 'repeat' => (bool) $r['rpt'],
        'qc_amount' => $r['qc_amount'] !== null ? (float) $r['qc_amount'] : null,
    ], $st->fetchAll());
    json_out(['mode' => 'leads', 'flag' => $flag, 'count' => count($rows), 'total' => $total,
              'offset' => $offset, 'rows' => $rows]);
}

json_out(['error' => 'unknown action', 'actions' => ['summary', 'leads']], 400);
