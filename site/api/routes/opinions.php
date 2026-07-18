<?php
declare(strict_types=1);

/**
 * GET /api/opinions — MODIFIED AUDIT OPINIONS dashboard.
 *
 * Two levels, selected by ?level= :
 *
 *   fs (default) — financial-statement opinion per audit (fac_general.gaap_results).
 *     Worst category wins when a report lists several opinion units:
 *       adverse     — the auditor says the statements do NOT fairly present (materially misstated)
 *       disclaimer  — the auditor could NOT obtain enough evidence to form an opinion
 *       qualified   — fairly presented EXCEPT for specific areas
 *     ('unqualified' guard: the substring 'qualified' sits inside the legacy word 'unqualified'.)
 *
 *   program — compliance opinion per MAJOR federal program (fac_federal_awards.audit_report_type,
 *     U/Q/A/D with is_major=1). One row per program-year, not per audit. Optional &hhs=1 narrows
 *     to HHS programs (ALN 93.*). Rides idx_fa_opinion (covering) — see
 *     migrations/2026-07-16_fac_federal_awards_opinion_index.sql.
 *
 * No pushed table — this computes on whatever FAC data the install holds (prod = HHS scope).
 *
 *   ?action=summary   per-category counts + facets (respects the filters below)
 *   ?action=leads     &cat=adverse|disclaimer|qualified &fy &etype &state &q &sort &dir &limit &offset
 */

require_once dirname(__DIR__) . '/lib/CohortFilter.php';

$action = q_str('action') ?? 'summary';
$level = q_str('level') === 'program' ? 'program' : 'fs';
$hhs = $level === 'program' && q_str('hhs') === '1';

const OPIN_RANK = ['adverse' => 1, 'disclaimer' => 2, 'qualified' => 3];

if ($level === 'program') {
    // per-major-program compliance opinion; A/D/Q letters map straight onto the categories
    $CAT = "CASE fa.audit_report_type WHEN 'A' THEN 'adverse' WHEN 'D' THEN 'disclaimer' ELSE 'qualified' END";
    $CAT_ORDER = "FIELD(fa.audit_report_type, 'A','D','Q')";
    $EXP = 'fa.amount_expended';
    $TABLES = 'FROM fac_federal_awards fa JOIN fac_general g ON g.report_id = fa.report_id
               LEFT JOIN entity e ON e.uei = g.auditee_uei';
    $baseConds = ['g.is_active = 1', 'fa.is_major = 1', "fa.audit_report_type IN ('A','D','Q')"];
    if ($hhs) $baseConds[] = "fa.aln LIKE '93.%'";
    // without this the optimizer leads with a full fac_general scan (170k rows x ~12 fa probes)
    // instead of the 33k-row idx_fa_opinion range; tables are already written best-first
    $HINT = 'STRAIGHT_JOIN ';
} else {
    // gaap_cat is a STORED generated column (worst-first opinion category), indexed via
    // idx_fg_gaap_cat — see migrations/2026-07-16_fac_general_gaap_cat.sql. Using it instead of
    // inline CASE/LIKE turned the summary from a ~5s scan into an indexed lookup.
    $CAT = 'g.gaap_cat';
    $CAT_ORDER = "FIELD(g.gaap_cat, 'adverse','disclaimer','qualified')";
    $EXP = 'g.total_amount_expended';
    $TABLES = 'FROM fac_general g LEFT JOIN entity e ON e.uei = g.auditee_uei';
    $baseConds = ['g.is_active = 1', "g.gaap_cat <> 'unmodified'"];
    $HINT = '';
}

$REGISTRY = aero_registry_ueis($pdo);

// shared filters (page-wide, mirroring the delinquency dashboard)
$fyS = q_str('fy');
$fy = ($fyS !== null && preg_match('/^\d{4}$/', $fyS)) ? (int) $fyS : null;
$etype = q_str('etype');
$etype = ($etype !== null && preg_match('/^[a-z-]{2,20}$/', $etype)) ? $etype : null;
$stateF = strtoupper((string) (q_str('state') ?? ''));
$q = trim((string) (q_str('q') ?? ''));

$conds = $baseConds;
$params = [];
if ($etype !== null) { aero_etype_cond($etype, $REGISTRY, 'g.auditee_uei', 'e.entity_type', $conds, $params); }
if (preg_match('/^[A-Z]{2}$/', $stateF)) { $conds[] = 'COALESCE(e.state, g.auditee_state) = ?'; $params[] = $stateF; }
if (mb_strlen($q) >= 2) {
    $conds[] = '(COALESCE(e.display_name, g.auditee_name) LIKE ? OR g.auditee_uei LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($fy !== null) { $conds[] = 'g.audit_year = ?'; $params[] = $fy; }
$FROM = "$TABLES WHERE " . implode(' AND ', $conds);

if ($action === 'summary') {
    $byCat = [];
    $st = $pdo->prepare("SELECT $HINT$CAT cat, COUNT(*) n, COUNT(DISTINCT g.auditee_uei) ueis,
                                SUM($EXP) exp $FROM GROUP BY cat");
    $st->execute($params);
    foreach ($st as $r) {
        if ($r['cat'] === 'unmodified') continue;
        $byCat[$r['cat']] = ['entity_years' => (int) $r['n'], 'entities' => (int) $r['ueis'],
                             'expended' => (float) $r['exp']];
    }
    // facets are unfiltered (beyond level/hhs) — they feed the dropdowns
    $BASE = "$TABLES WHERE " . implode(' AND ', $baseConds);
    $types = $pdo->query("SELECT $HINT DISTINCT e.entity_type " . str_replace('LEFT JOIN entity', 'JOIN entity', $BASE) . "
                          AND e.entity_type IS NOT NULL ORDER BY e.entity_type")->fetchAll(PDO::FETCH_COLUMN);
    if ($REGISTRY) {
        $in = implode(',', array_fill(0, count($REGISTRY), '?'));
        $st = $pdo->prepare("SELECT $HINT 1 $BASE AND g.auditee_uei IN ($in) LIMIT 1");
        $st->execute($REGISTRY);
        if ($st->fetchColumn()) array_unshift($types, 'stategov');
    }
    json_out([
        'mode' => 'opinions',
        'level' => $level,
        'by_cat' => $byCat,
        'fys' => array_map('intval', $pdo->query("SELECT $HINT DISTINCT g.audit_year $BASE
                    ORDER BY g.audit_year")->fetchAll(PDO::FETCH_COLUMN)),
        'types' => $types,
        'states' => $pdo->query("SELECT $HINT DISTINCT COALESCE(e.state, g.auditee_state) s $BASE
                    AND COALESCE(e.state, g.auditee_state) > '' ORDER BY s")->fetchAll(PDO::FETCH_COLUMN),
        'generated_at' => date('c'),
    ]);
}

if ($action === 'leads') {
    $cat = q_str('cat');
    if ($cat !== null && isset(OPIN_RANK[$cat])) {
        if ($level === 'program') {
            // letter filter hits idx_fa_opinion directly
            $conds[] = 'fa.audit_report_type = ?';
            $params[] = ['adverse' => 'A', 'disclaimer' => 'D', 'qualified' => 'Q'][$cat];
        } else {
            // narrow via the same derived column, so 'adverse,qualified' rows land in adverse only
            $conds[] = "$CAT = ?";
            $params[] = $cat;
        }
        $FROM = "$TABLES WHERE " . implode(' AND ', $conds);
    }
    $limit = q_int('limit', 100, 1, 25000);   // paging uses 100; CSV export pulls up to the cap
    $offset = q_int('offset', 0, 0, 1000000);
    $SORTS = [
        'expended' => $EXP,
        'name' => 'COALESCE(e.display_name, g.auditee_name)',
        'state' => "COALESCE(e.state, g.auditee_state, 'ZZ')",
        'fy' => 'g.audit_year',
        'opinion' => $CAT_ORDER,
        'program' => $level === 'program' ? 'fa.aln' : 'g.audit_year',
    ];
    $sortCol = $SORTS[q_str('sort') ?? 'expended'] ?? $EXP;
    $sortDir = q_str('dir') === 'asc' ? 'ASC' : 'DESC';

    $ct = $pdo->prepare("SELECT ${HINT}COUNT(*) $FROM");
    $ct->execute($params);
    $total = (int) $ct->fetchColumn();

    // award_reference lets the UI drill from a program row to the findings behind its opinion
    $progCols = $level === 'program' ? ', fa.aln, fa.federal_program_name prog, fa.award_reference award' : '';
    $st = $pdo->prepare("SELECT $HINT g.auditee_uei uei, COALESCE(e.display_name, g.auditee_name) name,
                COALESCE(e.state, g.auditee_state) state, g.audit_year fy, g.report_id,
                $CAT cat, $EXP expended$progCols" . ($level === 'fs' ? ', g.gaap_results' : '') . "
         $FROM ORDER BY $sortCol $sortDir, $EXP DESC LIMIT $limit OFFSET $offset");
    $st->execute($params);
    $rows = array_map(static fn ($r) => [
        'uei' => $r['uei'], 'name' => $r['name'], 'state' => $r['state'],
        'fy' => (int) $r['fy'], 'report_id' => $r['report_id'], 'cat' => $r['cat'],
        'gaap_results' => $r['gaap_results'] ?? null, 'expended' => (float) $r['expended'],
        'aln' => $r['aln'] ?? null, 'program' => $r['prog'] ?? null, 'award' => $r['award'] ?? null,
    ], $st->fetchAll());
    json_out(['mode' => 'leads', 'level' => $level, 'cat' => $cat, 'count' => count($rows),
              'total' => $total, 'offset' => $offset, 'rows' => $rows]);
}

// Findings behind ONE major program's compliance opinion — the drill-down under a Program
// Compliance row. Findings tie to a program through the fac_finding_awards bridge (award_reference);
// is_modified_opinion marks the ones that drove the opinion to modified (see the finding<->opinion
// walkthrough). MO-drivers first, then by reference.
if ($action === 'program_findings') {
    $rid = q_str('report_id');
    $award = q_str('award');
    if ($rid === null || $award === null) json_out(['error' => 'report_id and award required'], 400);
    $st = $pdo->prepare(
        "SELECT DISTINCT f.reference_number ref, f.type_requirement req,
                f.is_material_weakness mw, f.is_significant_deficiency sd, f.is_questioned_costs qc,
                f.is_modified_opinion mo, f.is_repeat_finding rpt, x.qc_amount
         FROM fac_finding_awards b
         JOIN fac_findings f ON f.report_id = b.report_id AND f.reference_number = b.reference_number
         LEFT JOIN fac_finding_extract x ON x.report_id = f.report_id AND x.finding_ref_number = f.reference_number
         WHERE b.report_id = ? AND b.award_reference = ?
         ORDER BY f.is_modified_opinion DESC, f.reference_number");
    $st->execute([$rid, $award]);
    $rows = array_map(static fn ($r) => [
        'ref' => $r['ref'], 'req' => $r['req'],
        'mw' => (bool) $r['mw'], 'sd' => (bool) $r['sd'], 'qc' => (bool) $r['qc'],
        'mo' => (bool) $r['mo'], 'repeat' => (bool) $r['rpt'],
        'qc_amount' => $r['qc_amount'] !== null ? (float) $r['qc_amount'] : null,
    ], $st->fetchAll());
    json_out(['mode' => 'program_findings', 'report_id' => $rid, 'award' => $award, 'rows' => $rows]);
}

json_out(['error' => 'unknown action', 'actions' => ['summary', 'leads', 'program_findings']], 400);
