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

// ON-DEMAND outlay load. A user on the USAspending tab can trigger a live per-entity File C /funding/
// pull (sync_usa_outlays.php --uei --related) that fills usa_award_outlay_month for this entity + its
// rollup members — exact MONTHLY outlays — then poll ?action=outlay_status until 'done'. It's the lazy,
// gentle alternative to the bulk backfill: one entity is a handful of calls, and it caches so the next
// viewer is instant. Local-only for now (it's a write + shell-out; enabling on prod is a separate call).
$action = q_str('action');
if ($action === 'outlay_status' || $action === 'load_outlays') {
    $statusFile = dirname(__DIR__) . '/cache/outlay_' . $uei . '.status';
    $status = is_file($statusFile) ? trim((string) file_get_contents($statusFile)) : 'idle';
    if ($action === 'outlay_status') json_out(['uei' => $uei, 'status' => $status]);

    // load_outlays: POST + local only, and don't relaunch one that's already in flight.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') json_out(['error' => 'POST required'], 405);
    if (!is_local_request()) json_out(['error' => 'on-demand outlay load is available only from the local install'], 403);
    if ($status === 'running') json_out(['started' => true, 'uei' => $uei, 'status' => 'running']);

    // Guard: on-demand is for typical entities (seconds-to-minutes). A big rollup (a state govt =
    // tens of thousands of awards) would crawl for HOURS and re-trip the API throttle — those get
    // primed offline/by the bulk matrix instead, not by a click.
    $ONDEMAND_CAP = 1500;
    $cnt = $pdo->prepare(
        "SELECT COUNT(*) FROM usa_award a
         WHERE a.category <> 'loan' AND a.total_outlay IS NOT NULL AND a.total_outlay <> 0
           AND (a.recipient_uei = ? OR a.recipient_uei IN (SELECT additional_uei FROM fac_additional_ueis WHERE auditee_uei = ?))"
    );
    $cnt->execute([$uei, $uei]);
    $awardCount = (int) $cnt->fetchColumn();
    if ($awardCount > $ONDEMAND_CAP) {
        json_out(['error' => 'too_large', 'uei' => $uei, 'awards' => $awardCount,
                  'message' => "This entity has $awardCount awards — too large to pull on demand; its outlays are prepared in the background instead."], 409);
    }

    $php = getenv('PHP_CLI') ?: (defined('PHP_BINARY') ? PHP_BINARY : 'php');
    $win = fn ($p) => str_replace('/', '\\', $p);
    $cacheDir = dirname(__DIR__) . '/cache';
    $logFile = $cacheDir . '/outlay_' . $uei . '.log';
    $bat = $cacheDir . '/outlay_' . $uei . '.bat';
    $cmd = '"' . $win($php) . '" "' . $win(dirname(__DIR__) . '/sync/sync_usa_outlays.php') . '" --uei=' . $uei . ' --related';
    file_put_contents(
        $bat,
        "@echo off\r\n"
        . 'echo running>"' . $win($statusFile) . '"' . "\r\n"
        . '( ' . $cmd . ' ) > "' . $win($logFile) . '" 2>&1' . "\r\n"
        . 'if "%errorlevel%"=="0" (echo done>"' . $win($statusFile) . '") else (echo failed>"' . $win($statusFile) . '")' . "\r\n"
    );
    @file_put_contents($statusFile, 'running');
    @pclose(@popen('cmd /c start "" /B cmd /c ' . escapeshellarg($win($bat)), 'r'));
    json_out(['started' => true, 'uei' => $uei, 'status' => 'running']);
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

// Affiliated component entities (rollup membership) — additional UEIs this auditee reported
// on its Single Audit UNION curated component links (entity_related_uei — agencies named in
// the parent's own SEFA but never declared on its SF-SAC, e.g. Oklahoma). Same sources as
// the Entity Info "Related UEIs" card, so the two surfaces stay consistent.
$memStmt = $pdo->prepare(
    "SELECT DISTINCT additional_uei FROM fac_additional_ueis WHERE auditee_uei IN ($selfIN)
     UNION
     SELECT DISTINCT related_uei FROM entity_related_uei WHERE uei IN ($selfIN)"
);
$memStmt->execute(array_merge($ueiSet, $ueiSet));
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

// Run the (capped) row query up front so the per-award month/outlay/program-grouping fetches
// below scope to just the awards actually shown — not every award in the rollup family. State
// rollups have tens of thousands of awards; fetching all their months then discarding all but the
// shown ~1,000 was the bottleneck. ($programMonths, the per-ALN aggregate, still spans the family.)
$rowStmt->execute($qset);
$rawRows  = $rowStmt->fetchAll();
$shownIds = array_values(array_unique(array_column($rawRows, 'award_id')));
// award_id IN (...) fetch, chunked to stay under the 65,535 bound-param limit at ?all=1 scale.
$byAwardIds = function (string $sql, array $ids) use ($pdo): array {
    $out = [];
    foreach (array_chunk($ids, 1000) as $chunk) {
        $st = $pdo->prepare(str_replace('{IN}', implode(',', array_fill(0, count($chunk), '?')), $sql));
        $st->execute($chunk);
        foreach ($st as $r) $out[] = $r;
    }
    return $out;
};

// Per-award transaction months (action-date obligations). Wrapped: a deployment without
// usa_award_txn_month degrades to the base-date 'fy' above.
$monthsByAward = [];
$programMonths = [];   // cfda => [[YYYY-MM, obligation], …]  (Comparative view, by program)
$cfdaTitles = [];
$dominantCfda = [];    // award_id => its most-obligated ALN, for award-level program grouping
try {
    // per-award months (sum across CFDA) + dominant ALN — SHOWN awards only (keyed by award_id)
    if ($shownIds) {
        foreach ($byAwardIds("SELECT award_id, DATE_FORMAT(ym,'%Y-%m') ym, SUM(obligation) obligation
                              FROM usa_award_txn_month WHERE award_id IN ({IN}) GROUP BY award_id, ym", $shownIds) as $r) {
            $monthsByAward[$r['award_id']][] = [$r['ym'], (float) $r['obligation']];
        }
        $bestObl = [];
        foreach ($byAwardIds("SELECT award_id, cfda, SUM(obligation) o
                              FROM usa_award_txn_month WHERE award_id IN ({IN}) AND cfda <> '' GROUP BY award_id, cfda", $shownIds) as $r) {
            $aw = $r['award_id']; $o = (float) $r['o'];
            if (!isset($bestObl[$aw]) || $o > $bestObl[$aw]) { $bestObl[$aw] = $o; $dominantCfda[$aw] = $r['cfda']; }
        }
    }

    // per-PROGRAM months, keyed by the transaction's own CFDA — spans the FULL rollup family
    // (the Comparative program view is an aggregate, not limited to the shown award rows)
    $pmStmt = $pdo->prepare(
        "SELECT m.cfda, DATE_FORMAT(m.ym, '%Y-%m') ym, SUM(m.obligation) obligation
         FROM usa_award_txn_month m JOIN usa_award a ON a.award_id = m.award_id
         WHERE a.recipient_uei IN ($IN) AND m.cfda <> '' GROUP BY m.cfda, m.ym"
    );
    $pmStmt->execute($qset);
    foreach ($pmStmt as $r) $programMonths[$r['cfda']][] = [$r['ym'], (float) $r['obligation']];
} catch (\PDOException $e) {
    error_log('usa_awards: txn-month split unavailable: ' . $e->getMessage());
}

// Per-award OUTLAY months (File C, calendar-month deltas). Twin of $monthsByAward but for outlays,
// so the UI can split outlays across fiscal years exactly like obligations. Wrapped: a deployment
// without usa_award_outlay_month degrades to the award-lifetime total_outlay (still returned below).
$outlaysByAward = [];
if ($shownIds) {
    try {
        foreach ($byAwardIds("SELECT award_id, DATE_FORMAT(ym,'%Y-%m') ym, outlay
                              FROM usa_award_outlay_month WHERE award_id IN ({IN})", $shownIds) as $r) {
            $outlaysByAward[$r['award_id']][] = [$r['ym'], (float) $r['outlay']];
        }
    } catch (\PDOException $e) {
        error_log('usa_awards: outlay-month split unavailable: ' . $e->getMessage());
    }
}

// Program titles from assistance_listing — the app-wide federal program catalog (ALN -> name),
// the same reference the FAC views (findings/grantee/subaward/evaluation) already use. Previously
// read usa_award_cfda, which is LOCAL-ONLY, so ALNs showed as bare numbers on prod. Look up only
// the ALNs present in this view (small IN list) rather than scanning the whole catalog.
if ($programMonths) {
    $alns = array_keys($programMonths);
    $ph   = implode(',', array_fill(0, count($alns), '?'));
    try {
        $tStmt = $pdo->prepare("SELECT assistance_listing_id, title FROM assistance_listing WHERE assistance_listing_id IN ($ph) AND title IS NOT NULL");
        $tStmt->execute($alns);
        foreach ($tStmt as $r) $cfdaTitles[$r['assistance_listing_id']] = $r['title'];
    } catch (\PDOException $e) { /* titles optional */ }
}
$programOut = [];
foreach ($programMonths as $cfda => $mos) {
    $programOut[] = ['aln' => $cfda, 'title' => $cfdaTitles[$cfda] ?? null, 'months' => $mos];
}

// Audited SEFA expenditures per (ALN, audit year) — what the entity itself reported SPENDING in its
// Single Audit (fac_federal_awards.amount_expended), for the Comparative obligated-vs-expended view.
// audit_year IS the entity fiscal year, so this only lines up under the Entity-FY basis. is_active
// dedups resubmissions. Same UEI set as the awards so both sides cover the same rollup group.
// Entities in this view whose award crawl hit the per-recipient page cap (sync_truncated=1):
// their award lists — and therefore every total on the tab — are a FLOOR, not the full picture
// (largest awards kept, long tail not loaded). Surfaced so big rollups don't silently understate.
$truncated = 0;
try {
    $trStmt = $pdo->prepare("SELECT COUNT(*) FROM usa_recipient WHERE uei IN ($IN) AND sync_truncated = 1");
    $trStmt->execute($qset);
    $truncated = (int) $trStmt->fetchColumn();
} catch (\PDOException $e) { /* column absent on an old schema — hint just doesn't render */ }

$sefa = [];   // aln => { audit_year => amount_expended }
try {
    $sStmt = $pdo->prepare(
        "SELECT fa.aln, fa.audit_year, SUM(fa.amount_expended) exp
         FROM fac_federal_awards fa
         JOIN fac_general g ON g.report_id = fa.report_id AND g.is_active = 1
         WHERE fa.auditee_uei IN ($IN) AND fa.aln IS NOT NULL AND fa.amount_expended IS NOT NULL
         GROUP BY fa.aln, fa.audit_year"
    );
    $sStmt->execute($qset);
    foreach ($sStmt as $r) $sefa[$r['aln']][(int) $r['audit_year']] = (float) $r['exp'];
} catch (\PDOException $e) { /* SEFA optional — tab degrades to obligations-only */ }

$rows = [];
foreach ($rawRows as $r) {
    $rows[] = [
        'recipient_uei'   => $r['recipient_uei'],
        'recipient_name'  => $r['recipient_name'],
        'fy'              => $r['fy'] !== null ? (int) $r['fy'] : null,   // federal FY of base obligation (fallback)
        'obligated_on'    => $r['obligated_on'],
        'months'          => $monthsByAward[$r['award_id']] ?? null,      // [[YYYY-MM, obligation], …] action-date split
        'outlay_months'   => $outlaysByAward[$r['award_id']] ?? null,     // [[YYYY-MM, outlay], …] File C calendar-month split
        'award_ref'       => $r['award_ref'],
        'award_id'        => $r['award_id'],   // generated_internal_id — links to usaspending.gov/award/{id}
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
    'outlay_available' => count($outlaysByAward) > 0,      // File C calendar-month outlay split present for this view
    'program_months' => $programOut,                       // [{aln, title, months:[[YYYY-MM, obligation],…]}] for Comparative
    'sefa'        => $sefa ?: null,                        // aln => {audit_year: amount_expended} — audited SEFA spend
    'truncated_entities' => $truncated,                    // recipients whose award list hit the sync cap (totals = floor)
    'rows'        => $rows,
    // Rollup metadata — drives the "include N affiliated entities" toggle + coverage hint.
    'rollup' => [
        'included'        => $rollup,
        'member_count'    => count($members),     // affiliated entities (fac_additional_ueis)
        'members_synced'  => $membersSynced,       // how many have USAspending awards cached
        'group_size'      => count($qset),         // total entities in the rollup group (parent + affiliated)
    ],
]);
