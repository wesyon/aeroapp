<?php
declare(strict_types=1);
/**
 * GET /api/usa_awards?uei=XXXXXXXXXXXX[&rollup=1][&all=1] — USAspending federal PRIME awards.
 * Reads usa_award directly (which IS synced on prod, unlike the per-ALN usa_award_cfda that
 * is kept off for quota), so this surface works on prod and local alike. Distinct from the
 * FAC "Awards" tab, which shows auditee-reported SEFA expenditures — this is the authoritative
 * award side (obligations / outlays) from USAspending.
 *
 * Rollup: a parent entity (e.g. a state government) reports its component agencies as
 * additional UEIs on its Single Audit (fac_additional_ueis — the same set shown as "Related
 * UEIs" in Entity Info). "?rollup=1" aggregates those members' awards together with the
 * entity's own, so e.g. State of Illinois shows its full footprint, not just the sliver filed
 * under the central UEI. Membership is a local FAC lookup (no API dependency); members still
 * need their awards synced (sync_usa.php --related=UEI) to contribute.
 *
 * Default returns the top 1,000 awards by obligation; "?all=1" lifts the cap. Totals are
 * always computed over the full set, independent of the row cap.
 */

$uei = q_str('uei');
if ($uei === null || !preg_match('/^[A-Za-z0-9]{12}$/', $uei)) {
    json_out(['error' => 'a valid 12-char uei is required'], 400);
}

// Multi-UEI governments: expand to the whole crosswalk group so awards filed under former
// UEIs are included (mirrors grantee.php / subaward.php).
$ueiSet = [$uei];
$grpStmt = $pdo->prepare("SELECT ueis FROM state_uei WHERE ueis LIKE ?");
$grpStmt->execute(['%' . $uei . '%']);
if (($grpUeis = $grpStmt->fetchColumn()) !== false && $grpUeis !== null) {
    $set = array_values(array_filter(array_map('trim', preg_split('/\R+/', (string) $grpUeis) ?: [])));
    if (count($set) > 1 && in_array($uei, $set, true)) $ueiSet = $set;
}
$selfIN = implode(',', array_fill(0, count($ueiSet), '?'));

// Affiliated component entities (rollup membership) — distinct additional UEIs this auditee
// reported on its Single Audit, excluding the entity's own UEI(s). Same source as the
// Entity Info "Related UEIs" card, so the two surfaces stay consistent.
$memStmt = $pdo->prepare(
    "SELECT DISTINCT additional_uei FROM fac_additional_ueis WHERE auditee_uei IN ($selfIN)"
);
$memStmt->execute($ueiSet);
$members = array_values(array_filter(
    $memStmt->fetchAll(PDO::FETCH_COLUMN),
    fn ($u) => $u !== null && $u !== '' && !in_array($u, $ueiSet, true)
));

// How many members already have USAspending awards cached? Drives the "X of N affiliated
// entities loaded" coverage hint (members are outside the findings-scoped sync until pulled).
$membersSynced = 0;
if ($members) {
    $mIN = implode(',', array_fill(0, count($members), '?'));
    $msStmt = $pdo->prepare("SELECT COUNT(*) FROM usa_recipient WHERE uei IN ($mIN) AND last_synced IS NOT NULL");
    $msStmt->execute($members);
    $membersSynced = (int) $msStmt->fetchColumn();
}

// The profiled entity's fiscal-year-end month (from its latest active FAC filing). Lets the UI
// offer an "Auditee FY" basis for the obligation year alongside the canonical federal FY — for a
// June-30 FYE, an obligation on 7/1 lands in the NEXT auditee FY. NULL = no FAC FYE on file, so
// the UI's auditee basis falls back to federal. Rollups use the parent's FYE for all members.
$fyeStmt = $pdo->prepare(
    "SELECT MONTH(fy_end_date) FROM fac_general
     WHERE auditee_uei IN ($selfIN) AND fy_end_date IS NOT NULL AND is_active = 1
     ORDER BY audit_year DESC LIMIT 1"
);
$fyeStmt->execute($ueiSet);
$fyeMonth = $fyeStmt->fetchColumn();
$fyeMonth = ($fyeMonth !== false && $fyeMonth !== null) ? (int) $fyeMonth : null;

// Scope: own UEIs, or own + affiliated members when rolled up.
$rollup = q_str('rollup') === '1' && $members;
$qset   = $rollup ? array_merge($ueiSet, $members) : $ueiSet;
$IN     = implode(',', array_fill(0, count($qset), '?'));

const USA_AWARD_ROW_LIMIT = 1000;                              // default cap; totals span all
$rowLimit = q_str('all') === '1' ? 100000 : USA_AWARD_ROW_LIMIT;

// Totals over the full set (loans carry value in total_loan_value, not total_obligation).
$aggStmt = $pdo->prepare(
    "SELECT COUNT(*) n,
            SUM(total_obligation) obligation,
            SUM(total_outlay) outlay,
            SUM(total_loan_value) loan_value,
            COUNT(DISTINCT recipient_uei) entities,
            MAX(last_synced) synced
     FROM usa_award WHERE recipient_uei IN ($IN)"
);
$aggStmt->execute($qset);
$agg = $aggStmt->fetch() ?: [];

// fy = FEDERAL fiscal year of the award's base obligation (date_signed). Kept as a FALLBACK for
// awards lacking transaction-level data; when present, usa_award_txn_month carries the per-month
// obligations so the UI can split an award across fiscal years by action_date (matching
// USAspending.gov) and bucket into the entity or federal FY at view time.
$rowStmt = $pdo->prepare(
    "SELECT award_id, recipient_uei, recipient_name,
            (YEAR(date_signed) + IF(MONTH(date_signed) >= 10, 1, 0)) fy,
            date_signed obligated_on, fain award_ref, category,
            award_type_description, awarding_toptier_agency, awarding_subtier_agency,
            funding_toptier_agency, total_obligation, total_outlay, total_loan_value,
            total_subsidy_cost, period_start_date, period_end_date
     FROM usa_award
     WHERE recipient_uei IN ($IN)
     ORDER BY COALESCE(total_obligation, total_loan_value, 0) DESC, period_end_date DESC
     LIMIT $rowLimit"
);

// Per-award transaction months (action-date obligations). Wrapped: a deployment without
// usa_award_txn_month degrades to the base-date 'fy' above.
$monthsByAward = [];
$programMonths = [];   // cfda => [[YYYY-MM, obligation], …]  (Comparative view, by program)
$cfdaTitles = [];
$dominantCfda = [];    // award_id => its most-obligated ALN, for award-level program grouping
try {
    // per-award months (sum across CFDA) for the award-level FY split
    $mStmt = $pdo->prepare(
        "SELECT m.award_id, DATE_FORMAT(m.ym, '%Y-%m') ym, SUM(m.obligation) obligation
         FROM usa_award_txn_month m JOIN usa_award a ON a.award_id = m.award_id
         WHERE a.recipient_uei IN ($IN) GROUP BY m.award_id, m.ym"
    );
    $mStmt->execute($qset);
    foreach ($mStmt as $r) $monthsByAward[$r['award_id']][] = [$r['ym'], (float) $r['obligation']];

    // per-PROGRAM months, keyed by the transaction's own CFDA (accurate; no multi-ALN guessing)
    $pmStmt = $pdo->prepare(
        "SELECT m.cfda, DATE_FORMAT(m.ym, '%Y-%m') ym, SUM(m.obligation) obligation
         FROM usa_award_txn_month m JOIN usa_award a ON a.award_id = m.award_id
         WHERE a.recipient_uei IN ($IN) AND m.cfda <> '' GROUP BY m.cfda, m.ym"
    );
    $pmStmt->execute($qset);
    foreach ($pmStmt as $r) $programMonths[$r['cfda']][] = [$r['ym'], (float) $r['obligation']];

    // per-award dominant ALN (the CFDA with the most obligation) — for award-level program grouping
    $acStmt = $pdo->prepare(
        "SELECT m.award_id, m.cfda, SUM(m.obligation) o
         FROM usa_award_txn_month m JOIN usa_award a ON a.award_id = m.award_id
         WHERE a.recipient_uei IN ($IN) AND m.cfda <> '' GROUP BY m.award_id, m.cfda"
    );
    $acStmt->execute($qset);
    $bestObl = [];
    foreach ($acStmt as $r) {
        $aw = $r['award_id']; $o = (float) $r['o'];
        if (!isset($bestObl[$aw]) || $o > $bestObl[$aw]) { $bestObl[$aw] = $o; $dominantCfda[$aw] = $r['cfda']; }
    }
} catch (\PDOException $e) {
    error_log('usa_awards: txn-month split unavailable: ' . $e->getMessage());
}
// CFDA titles (usa_award_cfda is local-only; degrade to ALN-only where absent)
if ($programMonths) {
    try {
        foreach ($pdo->query("SELECT cfda_number, MAX(cfda_title) cfda_title FROM usa_award_cfda WHERE cfda_title IS NOT NULL GROUP BY cfda_number") as $r) {
            $cfdaTitles[$r['cfda_number']] = $r['cfda_title'];
        }
    } catch (\PDOException $e) { /* titles optional */ }
}
$programOut = [];
foreach ($programMonths as $cfda => $mos) {
    $programOut[] = ['aln' => $cfda, 'title' => $cfdaTitles[$cfda] ?? null, 'months' => $mos];
}

$rowStmt->execute($qset);
$rows = [];
foreach ($rowStmt as $r) {
    $rows[] = [
        'recipient_uei'   => $r['recipient_uei'],
        'recipient_name'  => $r['recipient_name'],
        'fy'              => $r['fy'] !== null ? (int) $r['fy'] : null,   // federal FY of base obligation (fallback)
        'obligated_on'    => $r['obligated_on'],
        'months'          => $monthsByAward[$r['award_id']] ?? null,      // [[YYYY-MM, obligation], …] action-date split
        'award_ref'       => $r['award_ref'],
        'category'        => $r['category'],
        'award_type'      => $r['award_type_description'],
        'agency'          => $r['awarding_toptier_agency'],
        'sub_agency'      => $r['awarding_subtier_agency'],
        'funding_agency'  => $r['funding_toptier_agency'],
        'aln'             => $dominantCfda[$r['award_id']] ?? null,   // dominant CFDA, for program grouping

        'obligation'      => $r['total_obligation']   !== null ? (float) $r['total_obligation']   : null,
        'outlay'          => $r['total_outlay']       !== null ? (float) $r['total_outlay']       : null,
        'loan_value'      => $r['total_loan_value']   !== null ? (float) $r['total_loan_value']   : null,
        'subsidy_cost'    => $r['total_subsidy_cost'] !== null ? (float) $r['total_subsidy_cost'] : null,
        'period_start'    => $r['period_start_date'],
        'period_end'      => $r['period_end_date'],
    ];
}

json_out([
    'uei'         => $uei,
    'synced'      => $agg['synced'] ?? null,
    'count'       => (int) ($agg['n'] ?? 0),
    'entities'    => (int) ($agg['entities'] ?? 0),       // distinct recipient UEIs contributing
    'obligation'  => $agg['obligation'] !== null ? (float) $agg['obligation'] : 0.0,
    'outlay'      => $agg['outlay']     !== null ? (float) $agg['outlay']     : 0.0,
    'loan_value'  => $agg['loan_value'] !== null ? (float) $agg['loan_value'] : 0.0,
    'capped'      => (int) ($agg['n'] ?? 0) > count($rows),
    'fye_month'   => $fyeMonth,                            // entity FYE month for the Auditee-FY basis
    'txn_available' => count($monthsByAward) > 0,          // action-date FY split present for this view
    'program_months' => $programOut,                       // [{aln, title, months:[[YYYY-MM, obligation],…]}] for Comparative
    'rows'        => $rows,
    // Rollup metadata — drives the "include N affiliated entities" toggle + coverage hint.
    'rollup' => [
        'included'        => $rollup,
        'member_count'    => count($members),     // affiliated entities (fac_additional_ueis)
        'members_synced'  => $membersSynced,       // how many have USAspending awards cached
        'group_size'      => count($qset),         // total entities in the rollup group (parent + affiliated)
    ],
]);
