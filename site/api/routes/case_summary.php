<?php
declare(strict_types=1);
/**
 * GET /api/case_summary?uei=...&fy=2025[&agency=93]
 *
 * Assembles the derivable half of an HHS leadership Executive Summary — the memo
 * format ARD writes by hand when a cognizant-agency recipient asks to extend a
 * Single Audit deadline (see references/execsummary/). Everything here comes from
 * FAC + the delinquency kernel; the case-workflow facts the memo also carries
 * (extension-request dates, the determination, per-OpDiv management decisions)
 * are NOT public data and are supplied by the caseworker in the UI.
 *
 * `agency` is the 2-digit ALN prefix of the cognizant agency whose slice the memo
 * is written from (93 = HHS). It is a parameter rather than a constant so the
 * template works for any cognizant agency, not just HHS.
 */

require_once dirname(__DIR__) . '/lib/Agency.php';

$uei = q_str('uei');
if ($uei === null) json_out(['error' => 'uei is required'], 400);
$agency = q_str('agency') ?? '93';

// ---- entity + every filed audit (oldest->newest); the memo needs the filing
// history for the timeliness table and the newest audit for the findings overview.
$st = $pdo->prepare(
    "SELECT report_id, audit_year, auditee_name, auditee_state, entity_type,
            fy_end_date, audit_period_covered, total_amount_expended,
            cognizant_agency, oversight_agency, fac_accepted_date
     FROM fac_general
     WHERE auditee_uei = ? AND is_active = 1
     ORDER BY audit_year"
);
$st->execute([$uei]);
$audits = $st->fetchAll();
if (!$audits) json_out(['error' => 'no audits on file for this UEI'], 404);

$newest = $audits[count($audits) - 1];

// Cognizant agency is assigned per-audit; take the newest non-null. FAC leaves it
// null for recipients under an OVERSIGHT agency instead (< $50M threshold), so fall
// back to oversight and label which basis was used — the memo's first row.
$cogCode = null; $cogBasis = null;
for ($i = count($audits) - 1; $i >= 0; $i--) {
    if (($audits[$i]['cognizant_agency'] ?? null) !== null) { $cogCode = $audits[$i]['cognizant_agency']; $cogBasis = 'cognizant'; break; }
    if ($cogCode === null && ($audits[$i]['oversight_agency'] ?? null) !== null) { $cogCode = $audits[$i]['oversight_agency']; $cogBasis = 'oversight'; }
}

// ---- timeliness history: due date (FYE + 9 months, 2 CFR 200.512(a)) vs FAC acceptance.
$timeliness = [];
$filedYears = [];
foreach ($audits as $a) {
    $filedYears[(int) $a['audit_year']] = true;
    $due = $a['fy_end_date'] ? aero_deadline9($a['fy_end_date']) : null;
    $acc = $a['fac_accepted_date'] ? strtotime($a['fac_accepted_date']) : null;
    $late = ($due !== null && $acc !== null) ? (int) round(($acc - $due) / 86400) : null;
    $timeliness[] = [
        'audit_year' => (int) $a['audit_year'],
        'fy_end'     => $a['fy_end_date'],
        'due'        => $due !== null ? date('Y-m-d', $due) : null,
        'accepted'   => $a['fac_accepted_date'],
        'days_late'  => $late,
        'status'     => $late === null ? 'Unknown' : ($late > 0 ? 'Late' : 'On time'),
        'biennial'   => strtolower((string) $a['audit_period_covered']) === 'biennial',
    ];
}

// ---- subject year: the FY the extension request is about. Defaults to the first
// unfiled year after the newest filing. Its FYE is projected from the newest audit's
// FYE (same month/day) because no report exists yet to read one from.
$subjectFy = q_int('fy', (int) $newest['audit_year'] + 1, 2016, 2100);
$subjectFyEnd = null;
if ($newest['fy_end_date']) {
    $ny = (int) $newest['audit_year'];
    $subjectFyEnd = date('Y-m-d', aero_add_months_clamped($newest['fy_end_date'], 12 * ($subjectFy - $ny)));
}
$subjectDue = $subjectFyEnd ? aero_deadline9($subjectFyEnd) : null;
$today = strtotime(date('Y-m-d'));
$subject = [
    'fy'           => $subjectFy,
    'fy_end'       => $subjectFyEnd,
    'due'          => $subjectDue !== null ? date('Y-m-d', $subjectDue) : null,
    'filed'        => isset($filedYears[$subjectFy]),
    'days_overdue' => ($subjectDue !== null && !isset($filedYears[$subjectFy]) && $today > $subjectDue)
        ? (int) round(($today - $subjectDue) / 86400) : null,
];

// The delinquency kernel's own read on the subject year — the app independently
// detects the overdue filing and sizes the money at risk behind it.
$dq = $pdo->prepare(
    "SELECT class, fy_end, estimate, exposure, outlays, subawards, loans, prior_sefa,
            money_trust, covered, legacy_missing, basis
     FROM delinquency_preview WHERE uei = ? AND fy = ?"
);
$dq->execute([$uei, $subjectFy]);
$subject['delinquency'] = $dq->fetch() ?: null;

// ---- newest audit: money split by agency, so the memo can state the cognizant
// agency's dollars and its share of total federal awards expended.
$rid = $newest['report_id'];
$ag = $pdo->prepare(
    "SELECT federal_agency_prefix pfx, SUM(amount_expended) amt, COUNT(*) awards
     FROM fac_federal_awards WHERE report_id = ? GROUP BY pfx ORDER BY amt DESC"
);
$ag->execute([$rid]);
$byAgency = [];
$agencyAmt = 0.0;
foreach ($ag as $row) {
    $byAgency[] = [
        'prefix'  => $row['pfx'],
        'acronym' => Agency::PREFIX_ACR[$row['pfx']] ?? $row['pfx'],
        'amount'  => (float) $row['amt'],
        'awards'  => (int) $row['awards'],
    ];
    if ($row['pfx'] === $agency) $agencyAmt = (float) $row['amt'];
}
$totalExpended = (float) $newest['total_amount_expended'];

// Opinions on MAJOR programs only — a modified opinion on a non-major program is
// not the "qualified opinion on a major federal program" the memo reports.
$op = $pdo->prepare(
    "SELECT audit_report_type t, COUNT(*) n, SUM(amount_expended) amt
     FROM fac_federal_awards WHERE report_id = ? AND is_major = 1 GROUP BY t"
);
$op->execute([$rid]);
$opinions = [];
foreach ($op as $row) {
    $opinions[] = ['type' => $row['t'], 'count' => (int) $row['n'], 'amount' => (float) $row['amt']];
}

// ---- findings on the newest audit, whole-report and cognizant-agency slice.
$ft = $pdo->prepare(
    "SELECT COUNT(*) n, SUM(is_repeat_finding) rep, SUM(is_material_weakness) mw,
            SUM(is_significant_deficiency) sd, SUM(is_questioned_costs) qc
     FROM fac_findings WHERE report_id = ?"
);
$ft->execute([$rid]);
$allFindings = $ft->fetch();

// One row per (finding, award) — collapsed to one row per finding below. A finding
// can span many awards and several OpDivs, which is exactly how the memo attributes
// management decisions to CMS vs ACF.
$fd = $pdo->prepare(
    "SELECT f.reference_number ref, f.type_requirement type,
            f.is_material_weakness mw, f.is_significant_deficiency sd,
            f.is_repeat_finding rpt, f.is_questioned_costs qc, f.prior_finding_ref_numbers prior,
            a.aln, a.federal_program_name prog, a.amount_expended amt,
            a.audit_report_type opinion, a.is_major major,
            al.agency_code opdiv, e.qc_amount, cap.planned_action cap
     FROM fac_findings f
     JOIN fac_finding_awards fa ON fa.report_id = f.report_id AND fa.reference_number = f.reference_number
     JOIN fac_federal_awards a ON a.report_id = fa.report_id AND a.award_reference = fa.award_reference
     LEFT JOIN assistance_listing al ON al.assistance_listing_id = a.aln
     LEFT JOIN fac_finding_extract e ON e.report_id = f.report_id AND e.finding_ref_number = f.reference_number
     LEFT JOIN fac_corrective_action_plans cap ON cap.report_id = f.report_id AND cap.finding_ref_number = f.reference_number
     WHERE f.report_id = ? AND a.federal_agency_prefix = ?
     ORDER BY f.reference_number, a.aln"
);
$fd->execute([$rid, $agency]);

/**
 * The CAP's committed completion date. FAC stores the corrective action plan as one
 * free-text blob, so the date is parsed out of it. A repeat finding whose CAP names
 * no date — or names one that already passed before the audit was even accepted — is
 * the gap the memo's Appendix A exists to surface, so both are flagged rather than
 * silently rendered as text.
 */
function aero_cap_completion(?string $cap, ?string $acceptedDate, bool $isRepeat): array
{
    if ($cap === null || trim($cap) === '') {
        return ['text' => null, 'flag' => 'missing', 'note' => 'No corrective action plan on file'];
    }
    if (!preg_match('/Anticipated completion date[^:]*:\s*([^\r\n]*)/i', $cap, $m)) {
        return ['text' => null, 'flag' => 'unparsed', 'note' => 'CAP on file; no anticipated completion date stated'];
    }
    $raw = trim($m[1]);
    if ($raw === '') {
        return ['text' => null, 'flag' => 'blank', 'note' => 'Anticipated completion date left blank'];
    }
    if (preg_match('/^(n\/?a|none|tbd)\b/i', $raw)) {
        return ['text' => $raw, 'flag' => 'none', 'note' => 'No completion date committed'];
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return ['text' => $raw, 'flag' => 'ok', 'note' => null];
    }
    // A date already elapsed when the audit reached FAC means the CAP was closed out
    // on paper while the finding recurred — the strongest single CAP-quality signal.
    if ($acceptedDate !== null && $ts < strtotime($acceptedDate) && $isRepeat) {
        return ['text' => $raw, 'flag' => 'stale',
                'note' => 'Completion date precedes FAC acceptance on a repeat finding'];
    }
    return ['text' => $raw, 'flag' => 'ok', 'note' => null];
}

$findings = [];
foreach ($fd as $row) {
    $ref = $row['ref'];
    if (!isset($findings[$ref])) {
        $findings[$ref] = [
            'ref'       => $ref,
            'type'      => $row['type'],
            'mw'        => (int) $row['mw'],
            'sd'        => (int) $row['sd'],
            'repeat'    => (int) $row['rpt'],
            'qc'        => (int) $row['qc'],
            'qc_amount' => $row['qc_amount'] !== null ? (float) $row['qc_amount'] : null,
            'prior'     => $row['prior'],
            'programs'  => [],
            'opdivs'    => [],
            'cap'       => aero_cap_completion($row['cap'], $newest['fac_accepted_date'], (int) $row['rpt'] === 1),
        ];
    }
    // Key by ALN: a finding routinely cites several awards under the SAME assistance
    // listing, which would otherwise print as "93.767, 93.767, 93.778, 93.778". Keep
    // the largest award per listing so the amount stays meaningful.
    $aln = $row['aln'] ?? '';
    if (!isset($findings[$ref]['programs'][$aln]) || (float) $row['amt'] > $findings[$ref]['programs'][$aln]['amount']) {
        $findings[$ref]['programs'][$aln] = [
            'aln'     => $row['aln'],
            'name'    => $row['prog'],
            'amount'  => (float) $row['amt'],
            'opinion' => $row['opinion'],
            'major'   => (int) $row['major'],
        ];
    }
    if ($row['opdiv'] !== null && !isset($findings[$ref]['opdivs'][$row['opdiv']])) {
        $sub = Agency::HHS_SUB[$row['opdiv']] ?? null;
        $findings[$ref]['opdivs'][$row['opdiv']] = [
            'code'    => $row['opdiv'],
            'acronym' => $sub[0] ?? $row['opdiv'],
            'name'    => $sub[1] ?? null,
        ];
    }
}
foreach ($findings as &$f) {
    $f['opdivs'] = array_values($f['opdivs']);
    usort($f['programs'], fn ($a, $b) => $b['amount'] <=> $a['amount']);   // largest program first
    $f['programs'] = array_values($f['programs']);
}
unset($f);
$findings = array_values($findings);

// Roll the memo's classification counts off the per-finding collapse, NOT off the
// (finding, award) rows — a finding spanning 8 awards would otherwise count 8 times.
$agencyRoll = ['count' => count($findings), 'repeat' => 0, 'mw' => 0, 'sd' => 0,
               'neither' => 0, 'qc' => 0, 'qc_amount' => 0.0, 'cap_gaps' => 0];
$opdivRoll = [];
foreach ($findings as $f) {
    $agencyRoll['repeat'] += $f['repeat'];
    if ($f['mw']) $agencyRoll['mw']++;
    elseif ($f['sd']) $agencyRoll['sd']++;
    else $agencyRoll['neither']++;          // reported but neither MW nor SD
    if ($f['qc']) $agencyRoll['qc']++;
    if ($f['qc_amount'] !== null) $agencyRoll['qc_amount'] += $f['qc_amount'];
    if ($f['cap']['flag'] !== 'ok') $agencyRoll['cap_gaps']++;
    foreach ($f['opdivs'] as $o) {
        $k = $o['code'];
        if (!isset($opdivRoll[$k])) $opdivRoll[$k] = $o + ['findings' => 0, 'repeat' => 0];
        $opdivRoll[$k]['findings']++;
        $opdivRoll[$k]['repeat'] += $f['repeat'];
    }
}
usort($findings, fn ($a, $b) => strcmp($a['ref'], $b['ref']));
$opdivs = array_values($opdivRoll);
usort($opdivs, fn ($a, $b) => $b['findings'] <=> $a['findings']);

// ---- distinct programs touched by the agency's findings, largest first — the
// memo's "findings primarily affect …" sentence.
$progRoll = [];
foreach ($findings as $f) {
    foreach ($f['programs'] as $p) {
        $k = $p['aln'];
        if (!isset($progRoll[$k])) $progRoll[$k] = $p + ['findings' => 0];
        $progRoll[$k]['findings']++;
        $progRoll[$k]['amount'] = max($progRoll[$k]['amount'], $p['amount']);
    }
}
$programs = array_values($progRoll);
usort($programs, fn ($a, $b) => $b['amount'] <=> $a['amount']);

$newestDue = $newest['fy_end_date'] ? aero_deadline9($newest['fy_end_date']) : null;
$newestAcc = $newest['fac_accepted_date'] ? strtotime($newest['fac_accepted_date']) : null;

json_out([
    'entity' => [
        'uei'   => $uei,
        'name'  => $newest['auditee_name'],
        'state' => $newest['auditee_state'],
        'type'  => $newest['entity_type'],
    ],
    'agency' => [
        'prefix'  => $agency,
        'acronym' => Agency::PREFIX_ACR[$agency] ?? $agency,
    ],
    'cognizant' => [
        'code'    => $cogCode,
        'acronym' => $cogCode !== null ? (Agency::PREFIX_ACR[$cogCode] ?? $cogCode) : null,
        'basis'   => $cogBasis,
    ],
    'subject' => $subject,
    'latest'  => [
        'report_id'      => $rid,
        'audit_year'     => (int) $newest['audit_year'],
        'fy_end'         => $newest['fy_end_date'],
        'due'            => $newestDue !== null ? date('Y-m-d', $newestDue) : null,
        'accepted'       => $newest['fac_accepted_date'],
        'days_late'      => ($newestDue !== null && $newestAcc !== null)
            ? (int) round(($newestAcc - $newestDue) / 86400) : null,
        'total_expended' => $totalExpended,
        'agency_amount'  => $agencyAmt,
        'agency_pct'     => $totalExpended > 0 ? $agencyAmt / $totalExpended : null,
        'findings_total' => (int) $allFindings['n'],
        'findings_repeat_total' => (int) $allFindings['rep'],
    ],
    'agency_findings' => $agencyRoll,
    'by_agency'       => $byAgency,
    'opinions'        => $opinions,
    'opdivs'          => $opdivs,
    'programs'        => $programs,
    'findings'        => $findings,
    'timeliness'      => $timeliness,
]);
