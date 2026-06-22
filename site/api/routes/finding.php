<?php
declare(strict_types=1);
/**
 * GET /api/finding?report_id=...&ref=... — one finding's parsed detail.
 * Joins fac_findings (flags/type) + fac_general (entity) with the parsed
 * fac_finding_extract row (GAGAS sections + questioned-cost amounts).
 */

require_once dirname(__DIR__) . '/lib/Lineage.php';
require_once dirname(__DIR__) . '/lib/Agency.php';

$rid = q_str('report_id');
$ref = q_str('ref');
if ($rid === null || $ref === null) json_out(['error' => 'report_id and ref are required'], 400);

$stmt = $pdo->prepare(
    "SELECT f.report_id, f.reference_number ref, f.type_requirement type, f.audit_year,
            f.is_material_weakness mw, f.is_significant_deficiency sd, f.is_modified_opinion mo,
            f.is_questioned_costs qc, f.is_repeat_finding rpt, (f.is_other_findings=1 OR f.is_other_matters=1) other_fm,
            f.prior_finding_ref_numbers prior,
            g.auditee_uei uei, g.auditee_name name,
            e.criteria, e.finding_condition, e.cause, e.effect, e.questioned_costs, e.recommendation,
            e.context, e.auditee_response, e.qc_known, e.qc_likely, e.qc_amount, e.qc_basis, e.qc_stated_zero,
            e.sample_size, e.sections_found, cap.planned_action cap, t.finding_text raw_text
     FROM fac_findings f
     JOIN fac_general g ON g.report_id = f.report_id
     LEFT JOIN fac_finding_extract e ON e.report_id = f.report_id AND e.finding_ref_number = f.reference_number
     LEFT JOIN fac_corrective_action_plans cap ON cap.report_id = f.report_id AND cap.finding_ref_number = f.reference_number
     LEFT JOIN fac_findings_text t ON t.report_id = f.report_id AND t.finding_ref_number = f.reference_number
     WHERE f.report_id = ? AND f.reference_number = ?"
);
$stmt->execute([$rid, $ref]);
$r = $stmt->fetch();
if (!$r) json_out(['error' => 'finding not found'], 404);

// ---- lineage: walk this finding's recurrence chain and locate where the trail goes cold ----
// Builds an oldest->newest timeline of nodes: VERIFIED (a loaded finding we traced through),
// GAP (an audit the entity filed but this finding wasn't carried — lapse-and-return), and
// DOCUMENTED (a year the auditor named in the prior-ref list but that predates our records).
// The break marks the deepest point we can independently verify and why we stop there.
$lineage = null;
$lr = $pdo->prepare(
    "SELECT g.audit_year yr, f.reference_number ref, f.prior_finding_ref_numbers pr, f.is_repeat_finding rep
     FROM fac_findings f JOIN fac_general g ON g.report_id = f.report_id AND g.is_active = 1
     WHERE f.auditee_uei = ?"
);
$lr->execute([$r['uei']]);
// maps for the shared lineage kernel (lib/Lineage.php): prior/priorYears are keyed for
// repeat findings only, per the kernel contract.
$rYear = []; $rep = []; $prior = []; $priorYears = [];
foreach ($lr as $x) {
    $rYear[$x['ref']] = (int) $x['yr'];
    $rep[$x['ref']]   = (int) $x['rep'];
    if ((int) $x['rep'] === 1) {
        $prior[$x['ref']]      = aero_first_prior($x['pr']);
        $priorYears[$x['ref']] = aero_prior_years($x['pr']);
    }
}
$fy = $pdo->prepare("SELECT DISTINCT audit_year FROM fac_general WHERE auditee_uei = ? AND is_active = 1");
$fy->execute([$r['uei']]);
$filed = [];
foreach ($fy as $x) $filed[(int) $x['audit_year']] = true;
$windowStart = $filed ? min(array_keys($filed)) : null;

if (isset($rYear[$ref])) {
    // verified timeline, depths, lapse/lookback/documented states and the chain break — all
    // from the one shared kernel (lib/Lineage.php), so the finding page and the profile Chain
    // tab render identical lineage.
    $lineage = Lineage::nodes($ref, [
        'refYear' => $rYear, 'prior' => $prior, 'rep' => $rep, 'priorYears' => $priorYears,
    ], $filed);
}

// ---- federal sub-agencies / programs this finding is tied to (via its awards) ----
// The findings list collapses these to a few badges; the detail page shows them all.
$awStmt = $pdo->prepare(
    "SELECT fa.federal_agency_prefix prefix, al.agency_code code, fa.aln,
            MAX(fa.federal_program_name) program, MAX(al.agency) agency
     FROM fac_finding_awards fwa
     JOIN fac_federal_awards fa ON fa.report_id = fwa.report_id AND fa.award_reference = fwa.award_reference
     LEFT JOIN assistance_listing al ON al.assistance_listing_id = fa.aln
     WHERE fwa.report_id = ? AND fwa.reference_number = ?
     GROUP BY fa.federal_agency_prefix, al.agency_code, fa.aln"
);
$awStmt->execute([$rid, $ref]);
$programs = []; $subsMap = [];
foreach ($awStmt as $a) {
    $acr = Agency::acr($a['prefix'], $a['code']);
    $hhs = Agency::isHhs($a['prefix']);
    $programs[] = ['aln' => $a['aln'], 'program' => $a['program'], 'sub' => $acr, 'hhs' => $hhs];
    if (!isset($subsMap[$acr])) $subsMap[$acr] = ['acr' => $acr, 'name' => Agency::name($a['prefix'], $a['code'], $a['agency']), 'hhs' => $hhs];
}
usort($programs, fn ($x, $y) => strcmp((string) $x['aln'], (string) $y['aln']));
$subs = array_values($subsMap);
usort($subs, fn ($x, $y) => ((int) $y['hhs'] <=> (int) $x['hhs']) ?: strcmp($x['acr'], $y['acr']));

json_out([
    'report_id'  => $r['report_id'],
    'ref'        => $r['ref'],
    'subs'       => $subs,        // distinct sub-agencies (acronym + full name) for the badges
    'programs'   => $programs,    // per-ALN program detail (aln + name + sub-agency)
    'uei'        => $r['uei'],
    'name'       => $r['name'],
    'year'       => (int) $r['audit_year'],
    'type'       => $r['type'],
    'prior'      => $r['prior'],
    'flags'      => ['mw' => (int) $r['mw'], 'sd' => (int) $r['sd'], 'mo' => (int) $r['mo'],
                     'qc' => (int) $r['qc'], 'repeat' => (int) $r['rpt'], 'other' => (int) $r['other_fm']],
    'sections'   => [
        'criteria'         => $r['criteria'],
        'condition'        => $r['finding_condition'],
        'cause'            => $r['cause'],
        'effect'           => $r['effect'],
        'questioned_costs' => $r['questioned_costs'],
        'recommendation'   => $r['recommendation'],
        'context'          => $r['context'],
        'auditee_response' => $r['auditee_response'],
    ],
    'qc'         => ['known' => $r['qc_known'] !== null ? (int) $r['qc_known'] : null,
                     'likely' => $r['qc_likely'] !== null ? (int) $r['qc_likely'] : null,
                     'amount' => $r['qc_amount'] !== null ? (int) $r['qc_amount'] : null,
                     'basis' => $r['qc_basis'], 'stated_zero' => $r['qc_stated_zero']],
    'sample_size'    => $r['sample_size'] !== null ? (int) $r['sample_size'] : null,
    'sections_found' => $r['sections_found'] !== null ? (int) $r['sections_found'] : null,
    'cap'            => $r['cap'],
    'text'           => $r['raw_text'],   // full FAC narrative (fallback when no sections parsed)
    'lineage'        => $lineage,         // recurrence timeline + where verification breaks
]);
