<?php
declare(strict_types=1);
/** GET /api/grantee?uei=XXXXXXXXXXXX — cross-source profile for one grantee. */

require_once dirname(__DIR__) . '/lib/Lineage.php';

$uei = q_str('uei');
if ($uei === null || !preg_match('/^[A-Za-z0-9]{12}$/', $uei)) {
    json_out(['error' => 'a valid 12-char uei is required'], 400);
}

// Multi-UEI governments: a UEI that belongs to a state_uei group expands to the whole
// group, so the profile covers the government across its UEI succession (filings under
// the old UEI through FY2022, the new one from FY2023 — fac_additional_ueis attests the
// linkage). $uei becomes the group's CURRENT member (latest active filing): SAM/score
// lookups and links use it; every FAC query below scopes to the full set.
$ueiSet = [$uei];
$isStateGov = false;   // uei is a state_uei registry member → this profile IS a state government
$grpStmt = $pdo->prepare("SELECT ueis FROM state_uei WHERE ueis LIKE ?");
$grpStmt->execute(['%' . $uei . '%']);
if (($grpUeis = $grpStmt->fetchColumn()) !== false && $grpUeis !== null) {
    $set = array_values(array_filter(array_map('trim', preg_split('/\R+/', (string) $grpUeis) ?: [])));
    $isStateGov = in_array($uei, $set, true);
    if (count($set) > 1 && $isStateGov) $ueiSet = $set;
}
$IN = implode(',', array_fill(0, count($ueiSet), '?'));
if (count($ueiSet) > 1) {
    $curStmt = $pdo->prepare("SELECT auditee_uei FROM fac_general WHERE auditee_uei IN ($IN) AND is_active = 1
                              ORDER BY audit_year DESC, fac_accepted_date DESC LIMIT 1");
    $curStmt->execute($ueiSet);
    if (($cur = $curStmt->fetchColumn()) !== false && $cur) $uei = $cur;
}

// identity (from the most recent audit)
$idStmt = $pdo->prepare(
    "SELECT auditee_uei uei, auditee_name name, auditee_state state, auditee_ein ein, entity_type,
            total_amount_expended, cognizant_agency
     FROM fac_general WHERE auditee_uei IN ($IN) AND is_active = 1 ORDER BY audit_year DESC, fac_accepted_date DESC LIMIT 1"
);
$idStmt->execute($ueiSet);
$identity = $idStmt->fetch();
if (!$identity) {
    json_out(['error' => 'grantee not found', 'uei' => $uei], 404);
}
// authoritative latest-active figures (were read from aero_score) — pulled here so the
// profile's federal/cognizant/proxy logic doesn't depend on a score row existing; kept out
// of the identity payload itself.
$federalLatest = $identity['total_amount_expended'] ?? null;
$cognizantCode = $identity['cognizant_agency'] ?? null;
unset($identity['total_amount_expended'], $identity['cognizant_agency']);
$identity['uei'] = $uei;                                            // the current member
$identity['former_ueis'] = array_values(array_diff($ueiSet, [$uei]));
$identity['is_stategov'] = $isStateGov;                             // registry member → State Govt scope

// Additional UEIs this auditee reported alongside its filing UEI(s) (fac_additional_ueis)
// — sister/component registrations of the SAME entity. Surfaced in Entity Info so a user
// who reached this profile via one of them (search resolves additional → primary) sees the
// link, with each one's SAM registration status. Exclude UEIs already shown as the
// current/former primary so there's no overlap with former_ueis above.
$addStmt = $pdo->prepare(
    "SELECT au.additional_uei uei, MAX(au.audit_year) last_year,
            MAX(COALESCE(se.legal_business_name, ur.name)) name, MAX(se.registration_status) reg_status
     FROM fac_additional_ueis au
     LEFT JOIN sam_entity se    ON se.uei = au.additional_uei
     LEFT JOIN usa_recipient ur ON ur.uei = au.additional_uei
     WHERE au.auditee_uei IN ($IN)
     GROUP BY au.additional_uei
     ORDER BY last_year DESC, au.additional_uei"
);
$addStmt->execute($ueiSet);
$additionalUeis = [];
foreach ($addStmt as $r) {
    if (in_array($r['uei'], $ueiSet, true)) continue;               // already the current/former primary
    $additionalUeis[] = [
        'uei'        => $r['uei'],
        'last_year'  => (int) $r['last_year'],
        'name'       => $r['name'],
        'reg_status' => $r['reg_status'],
        'src'        => 'fac',                                      // auditor-reported on the SF-SAC
    ];
}

// Curated component links (entity_related_uei) — agencies that belong to this reporting
// entity per its own audit's SEFA but were never declared as additional UEIs on the SF-SAC
// (e.g. Oklahoma FY2023). Tagged so the UI can distinguish auditor-reported from curated.
$curStmt = $pdo->prepare(
    "SELECT er.related_uei uei, er.note,
            MAX(COALESCE(se.legal_business_name, ur.name)) name, MAX(se.registration_status) reg_status
     FROM entity_related_uei er
     LEFT JOIN sam_entity se    ON se.uei = er.related_uei
     LEFT JOIN usa_recipient ur ON ur.uei = er.related_uei
     WHERE er.uei IN ($IN)
     GROUP BY er.related_uei, er.note"
);
$curStmt->execute($ueiSet);
$seen = array_column($additionalUeis, 'uei');
foreach ($curStmt as $r) {
    if (in_array($r['uei'], $ueiSet, true) || in_array($r['uei'], $seen, true)) continue;
    $additionalUeis[] = [
        'uei'        => $r['uei'],
        'last_year'  => null,
        'name'       => $r['name'],
        'reg_status' => $r['reg_status'],
        'src'        => 'curated',
        'note'       => $r['note'],
    ];
}

// Members that file their OWN single audits (e.g. Oklahoma DOT/DEQ) — surfaced on the
// Related UEIs card so self-filers read differently from components whose only audit
// coverage is the parent's statewide report.
if ($additionalUeis) {
    $auIN = implode(',', array_fill(0, count($additionalUeis), '?'));
    // Latest own filing per member, WITH its audit_type: a 'program-specific' audit covers
    // one grant, not the entity (e.g. Oklahoma DOT's FY2024 filing = a $3.3M rail-grant
    // audit against ~$1.1B/yr received) — the UI must not present it as full coverage.
    $ownStmt = $pdo->prepare(
        "SELECT auditee_uei, audit_year y, audit_type t FROM (
            SELECT auditee_uei, audit_year, audit_type,
                   ROW_NUMBER() OVER (PARTITION BY auditee_uei ORDER BY audit_year DESC) rn
            FROM fac_general WHERE auditee_uei IN ($auIN)
         ) x WHERE rn = 1"
    );
    $ownStmt->execute(array_column($additionalUeis, 'uei'));
    $own = [];
    foreach ($ownStmt as $r) $own[$r['auditee_uei']] = $r;
    foreach ($additionalUeis as &$au) {
        $au['own_year'] = isset($own[$au['uei']]) ? (int) $own[$au['uei']]['y'] : null;
        $au['own_type'] = $own[$au['uei']]['t'] ?? null;
    }
    unset($au);
}
$identity['additional_ueis'] = $additionalUeis;

// Reverse membership — parents whose reporting family THIS entity belongs to, from both
// link sources (auditor-declared additional UEIs and curated component links). Lets a
// component's own profile (e.g. Oklahoma DOT) point back at the government that answers
// for it, mirroring the parent's Related UEIs card.
$memberOf = [];
$moStmt = $pdo->prepare(
    "SELECT p.parent_uei, p.src, p.note,
            COALESCE(e.display_name, p.parent_uei) name
     FROM (
        SELECT DISTINCT auditee_uei parent_uei, 'fac' src, NULL note
        FROM fac_additional_ueis WHERE additional_uei IN ($IN)
        UNION
        SELECT uei parent_uei, 'curated' src, note
        FROM entity_related_uei WHERE related_uei IN ($IN)
     ) p
     LEFT JOIN entity e ON e.uei = p.parent_uei"
);
$moStmt->execute(array_merge($ueiSet, $ueiSet));
foreach ($moStmt as $r) {
    if (in_array($r['parent_uei'], $ueiSet, true)) continue;
    $memberOf[] = ['uei' => $r['parent_uei'], 'name' => $r['name'], 'src' => $r['src'], 'note' => $r['note']];
}
$identity['member_of'] = $memberOf;

// SAM registration (multi-UEI: prefer the active registration — old members lapse)
$samStmt = $pdo->prepare(
    "SELECT uei, legal_business_name, dba_name, registration_status, registration_date, registration_expiration_date,
            cage_code, purpose_of_registration_desc, purpose_of_registration_code, exclusion_status_flag, entity_structure,
            physical_address_line1, physical_address_city, physical_address_state, physical_address_zip,
            congressional_district, entity_start_date, fiscal_year_end_close_date, last_synced
     FROM sam_entity WHERE uei IN ($IN)
     ORDER BY (registration_status = 'Active') DESC,
              (registration_status NOT IN ('ID Assigned','Not Found')) DESC,  -- real registrations beat API-enriched placeholders
              registration_date DESC
     LIMIT 1"
);
$samStmt->execute($ueiSet);
$sam = $samStmt->fetch() ?: null;
if ($sam) {
    $btStmt = $pdo->prepare("SELECT type_desc FROM sam_business_type WHERE uei = ? AND type_desc IS NOT NULL ORDER BY type_desc");
    $btStmt->execute([$sam['uei']]);
    $sam['business_types'] = $btStmt->fetchAll(PDO::FETCH_COLUMN);
    $nzStmt = $pdo->prepare("SELECT naics_code, naics_description FROM sam_entity_naics WHERE uei = ? ORDER BY is_primary DESC, naics_code LIMIT 1");
    $nzStmt->execute([$sam['uei']]);
    $sam['naics'] = $nzStmt->fetch() ?: null;
}

// FAC latest-audit profile: identity/opinion flags, addresses, contacts, auditor
$genStmt = $pdo->prepare(
    "SELECT entity_type, audit_type, audit_period_covered, fy_end_date, dollar_threshold, gaap_results,
            is_low_risk_auditee, is_going_concern_included, is_internal_control_deficiency_disclosed,
            is_internal_control_material_weakness_disclosed,
            auditee_address_line_1, auditee_city, auditee_state, auditee_zip,
            auditee_contact_name, auditee_contact_title, auditee_email, auditee_phone,
            auditee_certify_name, auditee_certify_title,
            auditor_firm_name, auditor_contact_name, auditor_contact_title, auditor_email, auditor_phone,
            auditor_address_line_1, auditor_city, auditor_state, auditor_zip
     FROM fac_general WHERE auditee_uei IN ($IN) AND is_active = 1 ORDER BY audit_year DESC, fac_accepted_date DESC LIMIT 1"
);
$genStmt->execute($ueiSet);
$facProfile = $genStmt->fetch() ?: null;

// exclusions (is this grantee on the federal debarment list?)
$exStmt = $pdo->prepare(
    "SELECT classification_type, exclusion_type, exclusion_program, excluding_agency_name,
            activate_date, termination_date, record_status
     FROM sam_exclusion WHERE uei_sam IN ($IN) ORDER BY activate_date DESC"
);
$exStmt->execute($ueiSet);
$exclusions = $exStmt->fetchAll();

// The SAM monthly extract leaves some fields sparse — fill them from data we already hold:
if ($sam) {
    // Purpose/type: the extract carries the CODE (Z1/Z2) for ~93% of entities but the
    // description for only ~4%. Map the code (authoritative labels taken from the entities
    // that carry both).
    if (($sam['purpose_of_registration_desc'] ?? null) === null) {
        $sam['purpose_of_registration_desc'] = match ($sam['purpose_of_registration_code'] ?? '') {
            'Z1'    => 'Federal Assistance Awards',
            'Z2'    => 'All Awards',
            default => null,
        };
    }
    // Exclusion status: the extract's flag is sparse, so derive it from the authoritative
    // federal debarment list (sam_exclusion, already fetched above). Excluded = an active
    // record (no termination, or termination in the future); otherwise Not excluded.
    $excludedNow = false;
    foreach ($exclusions as $e) {
        if (($e['termination_date'] ?? null) === null || $e['termination_date'] > date('Y-m-d')) { $excludedNow = true; break; }
    }
    $sam['exclusion_status_flag'] = $excludedNow ? 'Y' : 'N';
}

/** 2 CFR 200.512 deadline (lib/Rules.php, unit-tested): single source for every
 *  lateness/overdue check on this page, so the audit table and the Level-1
 *  delinquency count always agree. */
$dl9 = static fn (string $fy): int => aero_deadline9($fy);

/** Preview of an auditor's QC narrative for the "as stated" column: the full text
 *  when it fits, otherwise cut at the last word boundary (no mid-word chops) with an
 *  ellipsis. $raw is a LEFT(.., $max+pad) slice; $more = the source is longer than $max. */
$qcPreview = static function ($raw, $more, int $max): ?string {
    $raw = trim((string) $raw);
    if ($raw === '') return null;
    if (!$more) return $raw;                       // whole narrative already present
    $cut = substr($raw, 0, $max);
    $sp  = strrpos($cut, ' ');
    if ($sp !== false && $sp > 0) $cut = substr($cut, 0, $sp);
    return rtrim($cut, " ,.;:") . '…';
};

// audits — every filed report, including superseded resubmissions (shown labelled
// in the history; all rollups elsewhere use the active report only)
$auStmt = $pdo->prepare(
    "SELECT g.report_id, g.auditee_uei filed_uei, g.audit_year, g.fy_end_date, g.total_amount_expended, g.fac_accepted_date, g.submitted_date, g.gaap_results, g.is_active, g.audit_period_covered,
            (SELECT COUNT(*) FROM fac_findings f WHERE f.report_id = g.report_id) findings,
            (SELECT COUNT(*) FROM fac_corrective_action_plans c WHERE c.report_id = g.report_id) caps,
            (SELECT COUNT(*) FROM fac_findings f WHERE f.report_id = g.report_id AND f.is_material_weakness = 1) mw,
            (SELECT COUNT(*) FROM fac_findings f WHERE f.report_id = g.report_id AND f.is_questioned_costs = 1) qc,
            (SELECT COUNT(*) FROM fac_findings f WHERE f.report_id = g.report_id AND f.is_repeat_finding = 1) repeat_n
     FROM fac_general g WHERE g.auditee_uei IN ($IN) ORDER BY g.audit_year DESC"
);
$auStmt->execute($ueiSet);
$audits = array_map(function ($a) use ($dl9, $uei) {
    $daysLate = ($a['fy_end_date'] && $a['submitted_date'])
        ? (int) floor((strtotime($a['submitted_date']) - $dl9($a['fy_end_date'])) / 86400)
        : null;
    return [
        'report_id'   => $a['report_id'],
        'filed_uei'   => $a['filed_uei'] !== $uei ? $a['filed_uei'] : null,   // provenance when filed under a former UEI
        'audit_year'  => (int) $a['audit_year'],
        'fy_end_date' => $a['fy_end_date'],
        'period'      => $a['audit_period_covered'],   // 'annual' | 'biennial' | 'other'
        'opinion'     => $a['gaap_results'],
        'expended'    => (float) $a['total_amount_expended'],
        'findings'    => (int) $a['findings'],
        'caps'        => (int) $a['caps'],
        'mw'          => (int) $a['mw'],
        'qc'          => (int) $a['qc'],
        'repeat'      => (int) $a['repeat_n'],
        'days_late'   => $daysLate,
        'accepted'    => $a['fac_accepted_date'],
        'missing'     => false,
        'superseded'  => ((int) $a['is_active']) === 0,
    ];
}, $auStmt->fetchAll());

// Missing / overdue audit years are surfaced as rows in this history too, so delinquency is
// visible and not just a number. They're appended once the shared missing-year walk has run
// (see $missingYearRows below) — the table and the Level-1 count read the SAME walk, rather
// than each projecting overdue years their own way.

// findings severity rollup (active reports only — superseded resubmissions excluded)
$fsStmt = $pdo->prepare(
    "SELECT COUNT(*) total, COALESCE(SUM(f.is_material_weakness=1),0) mw, COALESCE(SUM(f.is_repeat_finding=1),0) rpt,
            COALESCE(SUM(f.is_questioned_costs=1),0) qc, COALESCE(SUM(f.is_modified_opinion=1),0) mo
     FROM fac_findings f JOIN fac_general g ON g.report_id = f.report_id AND g.is_active = 1
     WHERE f.auditee_uei IN ($IN)"
);
$fsStmt->execute($ueiSet);
$findingsSummary = array_map('intval', $fsStmt->fetch() ?: []);

// findings-by-attribute per audit year (for the trend chart), deduped to the active
// (most-recently-accepted) report per audit_year so resubmissions don't double-count.
$ftStmt = $pdo->prepare(
    "SELECT f.audit_year yr,
            SUM(f.is_material_weakness=1) mw, SUM(f.is_significant_deficiency=1) sd,
            SUM(f.is_questioned_costs=1) qc, SUM(f.is_modified_opinion=1) modified,
            SUM(f.is_repeat_finding=1) repeat_n, COUNT(*) total
     FROM fac_findings f
     JOIN fac_general g ON g.report_id = f.report_id AND g.is_active = 1
     WHERE g.auditee_uei IN ($IN)
     GROUP BY f.audit_year ORDER BY f.audit_year"
);
$ftStmt->execute($ueiSet);
$findingTrends = array_map(fn ($r) => [
    'year'     => (int) $r['yr'],
    'mw'       => (int) $r['mw'],
    'sd'       => (int) $r['sd'],
    'qc'       => (int) $r['qc'],
    'modified' => (int) $r['modified'],
    'repeat'   => (int) $r['repeat_n'],
    'total'    => (int) $r['total'],
], $ftStmt->fetchAll());

// programs: FAC expended vs USAspending obligated, per ALN
$prog = [];
$expStmt = $pdo->prepare(
    "SELECT fa.aln, MAX(fa.federal_program_name) name, SUM(fa.amount_expended) expended
     FROM fac_federal_awards fa JOIN fac_general g ON g.report_id = fa.report_id AND g.is_active = 1
     WHERE fa.auditee_uei IN ($IN) AND fa.aln IS NOT NULL GROUP BY fa.aln"
);
$expStmt->execute($ueiSet);
foreach ($expStmt as $r) {
    // FAC's own program name as the fallback; the official catalog title (filled in
    // below) overwrites it when the ALN is in the catalog. Filer names are often
    // ALL CAPS — normalize like the footprint rows do.
    $facName = $r['name'] !== null ? ucwords(strtolower((string) $r['name'])) : null;
    $prog[$r['aln']] = ['aln' => $r['aln'], 'program' => $facName, 'expended' => (float) $r['expended'], 'obligated' => 0.0];
}
// USAspending obligated-by-ALN enrichment. usa_award_cfda is an optional, local-only
// table on the quota-bound prod DB (kept off to stay under the 3 GB cap), so a missing
// table degrades to "no obligated figures" rather than failing the whole profile.
try {
    $oblStmt = $pdo->prepare(
        "SELECT c.cfda_number aln, SUM(a.total_obligation) obligated
         FROM usa_award a JOIN usa_award_cfda c ON c.award_id = a.award_id
         WHERE a.recipient_uei IN ($IN) GROUP BY c.cfda_number"
    );
    $oblStmt->execute($ueiSet);
    foreach ($oblStmt as $r) {
        if (!isset($prog[$r['aln']])) {
            $prog[$r['aln']] = ['aln' => $r['aln'], 'program' => null, 'expended' => 0.0, 'obligated' => 0.0];
        }
        $prog[$r['aln']]['obligated'] = (float) $r['obligated'];
    }
} catch (\PDOException $e) {
    error_log('grantee: usa_award_cfda obligated enrichment skipped: ' . $e->getMessage());
}
// official program names
if ($prog) {
    $in = implode(',', array_fill(0, count($prog), '?'));
    $nmStmt = $pdo->prepare("SELECT assistance_listing_id id, title FROM assistance_listing WHERE assistance_listing_id IN ($in)");
    $nmStmt->execute(array_keys($prog));
    foreach ($nmStmt as $r) {
        if (isset($prog[$r['id']])) $prog[$r['id']]['program'] = $r['title'];
    }
}
$programs = array_values($prog);
usort($programs, fn ($a, $b) => $b['expended'] <=> $a['expended']);

// is USAspending data cached for this grantee yet?
$usStmt = $pdo->prepare("SELECT MAX(last_synced) FROM usa_recipient WHERE uei IN ($IN)");
$usStmt->execute($ueiSet);
$usaLastSynced = $usStmt->fetchColumn();                 // 'Y-m-d H:i:s' | false
$usaSynced     = (bool) $usaLastSynced;

// Prod-safe USAspending rollup, read straight from usa_award (which IS synced on prod,
// unlike the per-ALN usa_award_cfda kept off for quota). The $awardsObligated above is
// summed from usa_award_cfda and is therefore zero on prod — this gives a real obligation
// total + award count everywhere. Drives the profile-header summary stat.
$usAggStmt = $pdo->prepare(
    "SELECT COUNT(*) n, SUM(total_obligation) obl, MAX(period_end_date) thru
     FROM usa_award WHERE recipient_uei IN ($IN)"
);
$usAggStmt->execute($ueiSet);
$usAgg = $usAggStmt->fetch() ?: [];
$usaAwardCount      = (int) ($usAgg['n'] ?? 0);
$usaObligationTotal = $usAgg['obl'] !== null ? (float) $usAgg['obl'] : 0.0;
$usaLatestPeriodEnd = $usAgg['thru'] ?? null;

// AERO risk score (precomputed by sync/compute_scores.php)
$scStmt = $pdo->prepare("SELECT * FROM aero_score WHERE uei = ?");
$scStmt->execute([$uei]);
$scRow = $scStmt->fetch() ?: null;

$POSTURE = [
    'Clean'       => 'No reported findings — routine processing',
    'Minimal'     => 'Standard award conditions',
    'Moderate'    => 'Enhanced post-award monitoring',
    'Elevated'    => 'Specific award conditions (§200.208); quarterly reporting',
    'Substantial' => 'Designated high-risk; reimbursement-only draws',
    'Severe'      => 'Pre-award escalation; consider suspension/debarment referral',
];
$BANDS = ['Clean' => '0', 'Minimal' => '0–19', 'Moderate' => '20–39', 'Elevated' => '40–59', 'Substantial' => '60–79', 'Severe' => '80–100'];

$score = null;
$drivers = [];
$latestYear = null;   // the SCORED latest year (display metadata on the score card)
if ($scRow) {
    $num = fn ($v) => $v === null ? null : (float) $v;
    $drivers = json_decode((string) $scRow['drivers'], true) ?: [];
    $latestYear = (int) $scRow['latest_audit_year'];
    $score = [
        'composite'   => (float) $scRow['composite_score'],
        'tier'        => $scRow['tier'],
        'band'        => $BANDS[$scRow['tier']] ?? null,
        'posture'     => $POSTURE[$scRow['tier']] ?? null,
        'latest_year' => $latestYear,
        'subscores'   => [
            'internal_control'     => $num($scRow['sc_internal_control']),
            'repeat_findings'      => $num($scRow['sc_repeat_findings']),
            'questioned_costs'     => $num($scRow['sc_questioned_costs']),
            'reporting_timeliness' => $num($scRow['sc_reporting_timeliness']),
            'cash_financial'       => $num($scRow['sc_cash_financial']),
            'subrecipient'         => $num($scRow['sc_subrecipient']),
            'cap_quality'          => $num($scRow['sc_cap_quality']),
        ],
        'drivers'     => $drivers,
        'trend'       => json_decode((string) ($scRow['trend'] ?? 'null'), true),
        'computed_at' => $scRow['computed_at'],
    ];
}

// cognizant agency (2-digit code -> department name via the ALN catalog)
$agNames = agency_names($pdo);
$cogCode = $cognizantCode;   // authoritative (latest active report), not the score row
$cognizant = $cogCode ? ['code' => $cogCode, 'name' => $agNames[$cogCode] ?? null] : null;

// Delinquent audits (also the Evaluation framework's Level 1): audit years filed past
// the 9-month deadline (2 CFR 200.512), PLUS years now missing & overdue (no audit =
// no visibility). Computed once here so the attention card, the audit-history table and
// the Evaluation tab agree — all three read the one walk in lib/Rules.php.
$delinqLate = 0; $delinqMissing = 0; $delinqMissingUnverified = 0;
$delinqYears = [];   // specific years for the Level 1 drill-down
$filings = [];       // audit_year => ['fy', 'orig', 'bi'] — the shared walk's input
// fy_end_date is required (it anchors the deadline), but submitted_date is NOT filtered on:
// a year that HAS a report is filed regardless of whether FAC dated it. Requiring it here
// would drop such a year from the walk's input and re-surface it as "missing" — an audit that
// exists, reported as never filed. MIN() skips NULLs, and the walk reports a year with no
// submission date as 'filed' (timeliness simply unknown) rather than late or missing.
$dStmt = $pdo->prepare("SELECT audit_year, MAX(fy_end_date) fy, MIN(submitted_date) orig,
                               MAX(audit_period_covered = 'biennial') bi FROM fac_general
                        WHERE auditee_uei IN ($IN) AND fy_end_date IS NOT NULL GROUP BY audit_year");
$dStmt->execute($ueiSet);
foreach ($dStmt as $r) {
    $filings[(int) $r['audit_year']] = ['fy' => $r['fy'], 'orig' => $r['orig'], 'bi' => (int) $r['bi'] === 1];
}
$missingYearRows = [];   // year => fyEnd/days — the audit-history table's projected rows
if ($filings) {
    // A Single Audit is only required at >= the ~$1M threshold, which we can't verify for an
    // unfiled year (the SEFA total only exists in FAC if they filed), so two signals decide
    // whether a missing year counts — the expenditure PROXY and USAspending/FSRS award
    // ACTIVITY. Both live in lib/Rules.php (aero_filing_status / aero_activity_confirmer),
    // shared with /api/evaluation and the map precompute so the surfaces can't drift.
    $directIntervals = [];                                                        // ['Y-m-d' start, end] of direct awards
    $apStmt = $pdo->prepare(
        "SELECT period_start_date s, period_end_date e FROM usa_award
         WHERE recipient_uei IN ($IN) AND category IN ('grant','direct_payment')
           AND period_start_date IS NOT NULL AND period_end_date IS NOT NULL");
    $apStmt->execute($ueiSet);
    foreach ($apStmt as $r) $directIntervals[] = [$r['s'], $r['e']];
    // FSRS pass-through subaward YEARS the entity received (as sub). Read from the
    // subaward_edge aggregate, NOT the multi-GB sam_assistance_subaward detail — the
    // detail table is local-only (built by build_subaward_edge.php); prod ships only the
    // edge. Year granularity is sufficient for the FY-window activity check.
    $subYears = [];
    $psStmt = $pdo->prepare(
        "SELECT DISTINCT year FROM subaward_edge WHERE sub_vendor_uei IN ($IN)");
    $psStmt->execute($ueiSet);
    foreach ($psStmt as $r) { $subYears[(int) $r['year']] = true; }

    $nowY = (int) date('Y');
    $status = aero_filing_status(
        $filings,
        aero_activity_confirmer($directIntervals, $subYears),
        (float) ($federalLatest ?? 0),
        null,
        max($nowY, max(array_keys($filings)))
    );
    // Late years first, then missing — the drill-down's existing grouping.
    foreach ($status as $y => $s) {
        if ($s['st'] === 'late') { $delinqLate++; $delinqYears[] = ['year' => $y, 'status' => 'late', 'days' => $s['days']]; }
    }
    foreach ($status as $y => $s) {
        if ($s['st'] === 'missing') {
            $delinqMissing++;
            $delinqYears[] = ['year' => $y, 'status' => 'missing', 'confirmed_by' => $s['confirmed_by']];
        } elseif ($s['st'] === 'unverified') {
            $delinqMissingUnverified++;                                           // overdue but unconfirmed -> verify
        }
        // The audit-history table shows every overdue gap — confirmed or not — so a year the
        // count can't assert is still visible rather than silently dropped. 'confirmed_by' is
        // what separates the two there (null = the caveated "verify" case).
        if ($s['st'] === 'missing' || $s['st'] === 'unverified') {
            $missingYearRows[$y] = ['fy_end' => $s['fy_end'], 'days' => $s['days'], 'confirmed_by' => $s['confirmed_by']];
        }
    }
}
// Level 1 counts NOT-FILED (missing & overdue) years only; late-filed years remain in
// $delinqYears as reference but no longer trigger the level.
$delinquentAudits = $delinqMissing;

// Project those overdue years into the audit history (see the note in the $audits section).
// Appended after the real reports, which are ordered newest-first.
foreach ($missingYearRows as $y => $m) {
    $audits[] = [
        'report_id' => null, 'audit_year' => $y, 'fy_end_date' => $m['fy_end'],
        'expended' => null, 'findings' => null, 'caps' => null,
        'mw' => 0, 'qc' => 0, 'repeat' => 0,
        // not filed: days late keeps counting from the 9-month deadline
        'days_late' => $m['days'],
        'accepted' => null, 'opinion' => null, 'missing' => true,
        'confirmed_by' => $m['confirmed_by'],   // null = overdue but unconfirmed ("verify")
    ];
}

// cumulative finding rollup (on file across the recipient's audits)
$findCum = [
    'total'  => (int) ($findingsSummary['total'] ?? 0),
    'mw'     => (int) ($findingsSummary['mw'] ?? 0),
    'repeat' => (int) ($findingsSummary['rpt'] ?? 0),
    'qc'     => (int) ($findingsSummary['qc'] ?? 0),
];

// agency / sub-agency catalogs — shared with the finding-detail route via lib/Agency.php
require_once dirname(__DIR__) . '/lib/Agency.php';
$HHS_SUB    = Agency::HHS_SUB;     // HHS operating division: agency_code -> [acronym, name]
$PREFIX_ACR = Agency::PREFIX_ACR;  // top-level agency acronym by ALN prefix

// Federal footprint: the selected agency (default HHS/93) broken out by sub-agency /
// operating division, then EVERY OTHER agency as a single row. Acronyms come from
// $HHS_SUB (HHS divisions) / $PREFIX_ACR (top-level agencies); the UI colours HHS
// divisions vs other agencies and keeps the selected agency on top.
$fpPrefix = q_str('agency') ?? '93';
$ZERO = ['rf' => 0, 'mw' => 0, 'mo' => 0, 'qc' => 0];
$fattr = "COUNT(DISTINCT CASE WHEN f.is_repeat_finding=1 THEN CONCAT(f.report_id,'|',f.reference_number) END) rf,"
       . "COUNT(DISTINCT CASE WHEN f.is_material_weakness=1 THEN CONCAT(f.report_id,'|',f.reference_number) END) mw,"
       . "COUNT(DISTINCT CASE WHEN f.is_modified_opinion=1 THEN CONCAT(f.report_id,'|',f.reference_number) END) mo,"
       . "COUNT(DISTINCT CASE WHEN f.is_questioned_costs=1 THEN CONCAT(f.report_id,'|',f.reference_number) END) qc";

// (A) selected agency, grouped by sub-agency (agency_code)
// STRAIGHT_JOIN (here and on the two awards queries below): always drive from fa via
// idx_awards_uei_agency (auditee_uei leading) — the optimizer otherwise flips to a full
// fac_general+awards scan (~3.4M rows, 10s+) for award-heavy states, especially with a
// multi-UEI IN list.
$fpStmt = $pdo->prepare(
    "SELECT STRAIGHT_JOIN MAX(al.agency_code) code, MAX(al.agency) agency,
            COUNT(DISTINCT fa.report_id) audits, COALESCE(SUM(fa.amount_expended),0) federal
     FROM fac_federal_awards fa JOIN assistance_listing al ON al.assistance_listing_id = fa.aln
     JOIN fac_general gg ON gg.report_id = fa.report_id AND gg.is_active = 1
     WHERE fa.auditee_uei IN ($IN) AND fa.federal_agency_prefix = ? AND al.agency IS NOT NULL
     GROUP BY al.agency_code ORDER BY federal DESC"
);
$fpStmt->execute(array_merge($ueiSet, [$fpPrefix]));
$fcStmt = $pdo->prepare(
    "SELECT al.agency_code code, $fattr
     FROM fac_findings f
     JOIN fac_general gg ON gg.report_id = f.report_id AND gg.is_active = 1
     JOIN fac_finding_awards fwa ON fwa.report_id = f.report_id AND fwa.reference_number = f.reference_number
     JOIN fac_federal_awards fa  ON fa.report_id = fwa.report_id AND fa.award_reference = fwa.award_reference
     JOIN assistance_listing al  ON al.assistance_listing_id = fa.aln
     WHERE f.auditee_uei IN ($IN) AND fa.federal_agency_prefix = ? AND al.agency IS NOT NULL
     GROUP BY al.agency_code"
);
$fcStmt->execute(array_merge($ueiSet, [$fpPrefix]));
$findByCode = [];
foreach ($fcStmt as $r) $findByCode[$r['code']] = ['rf' => (int) $r['rf'], 'mw' => (int) $r['mw'], 'mo' => (int) $r['mo'], 'qc' => (int) $r['qc']];
$selRows = array_map(function ($r) use ($HHS_SUB, $findByCode, $fpPrefix, $ZERO) {
    $m = ($fpPrefix === '93') ? ($HHS_SUB[$r['code']] ?? null) : null;
    $fc = $findByCode[$r['code']] ?? $ZERO;
    return ['acronym' => $m[0] ?? null, 'sub_agency' => $m[1] ?? ucwords(strtolower((string) $r['agency'])),
            'audits' => (int) $r['audits'], 'federal' => (float) $r['federal']] + $fc;
}, $fpStmt->fetchAll());

// (B) every other agency, one row per top-level agency (prefix)
$ofStmt = $pdo->prepare(
    "SELECT STRAIGHT_JOIN fa.federal_agency_prefix prefix, MAX(al.department) dept,
            COUNT(DISTINCT fa.report_id) audits, COALESCE(SUM(fa.amount_expended),0) federal
     FROM fac_federal_awards fa LEFT JOIN assistance_listing al ON al.assistance_listing_id = fa.aln
     JOIN fac_general gg ON gg.report_id = fa.report_id AND gg.is_active = 1
     WHERE fa.auditee_uei IN ($IN) AND fa.federal_agency_prefix <> ? AND fa.federal_agency_prefix IS NOT NULL AND fa.federal_agency_prefix <> ''
     GROUP BY fa.federal_agency_prefix ORDER BY federal DESC"
);
$ofStmt->execute(array_merge($ueiSet, [$fpPrefix]));
$ofcStmt = $pdo->prepare(
    "SELECT fa.federal_agency_prefix prefix, $fattr
     FROM fac_findings f
     JOIN fac_general gg ON gg.report_id = f.report_id AND gg.is_active = 1
     JOIN fac_finding_awards fwa ON fwa.report_id = f.report_id AND fwa.reference_number = f.reference_number
     JOIN fac_federal_awards fa  ON fa.report_id = fwa.report_id AND fa.award_reference = fwa.award_reference
     WHERE f.auditee_uei IN ($IN) AND fa.federal_agency_prefix <> ?
     GROUP BY fa.federal_agency_prefix"
);
$ofcStmt->execute(array_merge($ueiSet, [$fpPrefix]));
$findByPrefix = [];
foreach ($ofcStmt as $r) $findByPrefix[$r['prefix']] = ['rf' => (int) $r['rf'], 'mw' => (int) $r['mw'], 'mo' => (int) $r['mo'], 'qc' => (int) $r['qc']];
$otherRows = array_map(function ($r) use ($PREFIX_ACR, $findByPrefix, $ZERO) {
    $p = $r['prefix'];
    $fc = $findByPrefix[$p] ?? $ZERO;
    return ['acronym' => $PREFIX_ACR[$p] ?? null, 'sub_agency' => $r['dept'] ? ucwords(strtolower((string) $r['dept'])) : ('Agency ' . $p),
            'audits' => (int) $r['audits'], 'federal' => (float) $r['federal']] + $fc;
}, $ofStmt->fetchAll());

$hhsFootprint = array_merge($selRows, $otherRows);

// --- Awards tab: one row per federal award (SEFA), with the findings linked to it ---
$subAcr = function ($prefix, $code) use ($HHS_SUB, $PREFIX_ACR) {
    if ($prefix === '93' && isset($HHS_SUB[$code])) return $HHS_SUB[$code][0];
    return $PREFIX_ACR[$prefix] ?? $prefix;
};
$awStmt = $pdo->prepare(
    "SELECT STRAIGHT_JOIN fa.report_id, fa.audit_year, fa.award_reference, fa.aln, fa.federal_program_name program, fa.cluster_name cluster,
            fa.federal_agency_prefix prefix, fa.amount_expended expended, al.agency_code,
            COALESCE(fc.findings,0) findings, COALESCE(fc.mw,0) mw, COALESCE(fc.qc,0) qc, COALESCE(fc.repeat_n,0) repeat_n
     FROM fac_federal_awards fa
     JOIN fac_general g ON g.report_id = fa.report_id AND g.is_active = 1
     LEFT JOIN assistance_listing al ON al.assistance_listing_id = fa.aln
     LEFT JOIN (
        SELECT fwa.report_id, fwa.award_reference,
               COUNT(DISTINCT CONCAT(fwa.report_id,'|',fwa.reference_number)) findings,
               COUNT(DISTINCT CASE WHEN f.is_material_weakness=1 THEN CONCAT(fwa.report_id,'|',fwa.reference_number) END) mw,
               COUNT(DISTINCT CASE WHEN f.is_questioned_costs=1  THEN CONCAT(fwa.report_id,'|',fwa.reference_number) END) qc,
               COUNT(DISTINCT CASE WHEN f.is_repeat_finding=1    THEN CONCAT(fwa.report_id,'|',fwa.reference_number) END) repeat_n
        FROM fac_finding_awards fwa JOIN fac_findings f ON f.report_id=fwa.report_id AND f.reference_number=fwa.reference_number
        WHERE fwa.auditee_uei IN ($IN)
        GROUP BY fwa.report_id, fwa.award_reference
     ) fc ON fc.report_id=fa.report_id AND fc.award_reference=fa.award_reference
     WHERE fa.auditee_uei IN ($IN)
     ORDER BY fa.audit_year DESC, fa.amount_expended DESC"
);
$awStmt->execute(array_merge($ueiSet, $ueiSet));

// associated findings per award (report_id|award_reference) so Awards rows can expand
$afStmt = $pdo->prepare(
    "SELECT fwa.report_id, fwa.award_reference, fwa.reference_number ref, f.type_requirement type,
            f.is_material_weakness mw, f.is_significant_deficiency sd, f.is_modified_opinion mo,
            f.is_questioned_costs qc, f.is_repeat_finding rpt, (f.is_other_findings=1 OR f.is_other_matters=1) other_fm,
            e.qc_amount, e.qc_basis, LEFT(e.questioned_costs, 90) qc_raw, CHAR_LENGTH(e.questioned_costs) > 70 qc_more,
            EXISTS(SELECT 1 FROM fac_corrective_action_plans c
                   WHERE c.report_id=fwa.report_id AND c.finding_ref_number=fwa.reference_number
                     AND c.planned_action IS NOT NULL AND c.planned_action<>'') has_cap
     FROM fac_finding_awards fwa
     JOIN fac_findings f ON f.report_id=fwa.report_id AND f.reference_number=fwa.reference_number
     LEFT JOIN fac_finding_extract e ON e.report_id=fwa.report_id AND e.finding_ref_number=fwa.reference_number
     WHERE fwa.auditee_uei IN ($IN) ORDER BY fwa.reference_number"
);
$afStmt->execute($ueiSet);
$findByAward = [];
foreach ($afStmt as $r) {
    $trusted = in_array($r['qc_basis'], ['known', 'generic', 'flagged', 'inline'], true) && (int) $r['qc_amount'] > 0;
    $findByAward[$r['report_id'] . '|' . $r['award_reference']][] = [
        'ref' => $r['ref'], 'report_id' => $r['report_id'], 'type' => $r['type'],
        'mw' => (int) $r['mw'], 'sd' => (int) $r['sd'], 'mo' => (int) $r['mo'],
        'qc' => (int) $r['qc'], 'repeat' => (int) $r['rpt'], 'other' => (int) $r['other_fm'],
        'cap' => (int) $r['has_cap'],
        'qc_dollars' => $trusted ? (int) $r['qc_amount'] : 0,
        'qc_text' => $trusted ? null : $qcPreview($r['qc_raw'], (int) $r['qc_more'] === 1, 70),
    ];
}

$awards = array_map(fn ($r) => [
    'fy'         => (int) $r['audit_year'],
    'report_id'  => $r['report_id'],
    'award_ref'  => $r['award_reference'],
    'finding_list' => $findByAward[$r['report_id'] . '|' . $r['award_reference']] ?? [],
    'aln'        => $r['aln'],
    'program'    => $r['program'],
    'cluster'    => $r['cluster'],
    'prefix'     => $r['prefix'],
    'sub_agency' => $subAcr($r['prefix'], $r['agency_code']),
    'expended'   => $r['expended'] !== null ? (float) $r['expended'] : 0.0,
    'findings'   => (int) $r['findings'],
    'mw'         => (int) $r['mw'], 'qc' => (int) $r['qc'], 'repeat' => (int) $r['repeat_n'],
], $awStmt->fetchAll());
$awardsObligated = array_sum(array_column($programs, 'obligated'));

// --- Findings tab: every finding with severity flags, CAP status, and the sub-agencies
//     (HHS operating division, or "Non-HHS") its linked awards belong to ---
$fndStmt = $pdo->prepare(
    "SELECT f.report_id, f.reference_number ref, f.audit_year, f.type_requirement type,
            f.is_material_weakness mw, f.is_significant_deficiency sd, f.is_modified_opinion mo,
            f.is_questioned_costs qc, f.is_repeat_finding rpt, (f.is_other_findings=1 OR f.is_other_matters=1) other_fm,
            e.qc_amount, e.qc_basis, e.questioned_costs qc_text,
            EXISTS(SELECT 1 FROM fac_corrective_action_plans c
                   WHERE c.report_id=f.report_id AND c.finding_ref_number=f.reference_number
                     AND c.planned_action IS NOT NULL AND c.planned_action<>'') has_cap
     FROM fac_findings f
     JOIN fac_general g ON g.report_id = f.report_id AND g.is_active = 1
     LEFT JOIN fac_finding_extract e ON e.report_id=f.report_id AND e.finding_ref_number=f.reference_number
     WHERE f.auditee_uei IN ($IN)
     ORDER BY f.audit_year DESC, f.reference_number"
);
$fndStmt->execute($ueiSet);
$findingsRaw = $fndStmt->fetchAll();

// repeat-finding lineage: walk prior_finding_ref_numbers across the active report per year
// (same logic the Evaluation tab uses) so each finding gets its recurrence chain.
$lrStmt = $pdo->prepare(
    "SELECT report_id, audit_year FROM fac_general WHERE auditee_uei IN ($IN) AND is_active = 1"
);
$lrStmt->execute($ueiSet);
$actRep = [];
foreach ($lrStmt as $r) $actRep[$r['report_id']] = (int) $r['audit_year'];
// Latest audit year from the FAC data ITSELF (active reports): aero_score can lag a
// sync (rescore failed or pending), so the Evaluation tab and attention cards key
// off this rather than the score row's latest_audit_year.
$facLatestYear = $actRep ? max($actRep) : null;
$lfStmt = $pdo->prepare("SELECT report_id, reference_number ref, prior_finding_ref_numbers pr, is_repeat_finding rep FROM fac_findings WHERE auditee_uei IN ($IN)");
$lfStmt->execute($ueiSet);
$rYear = []; $rReport = []; $rPrior = []; $rRep = []; $rPriorYears = [];
foreach ($lfStmt as $r) {
    if (!isset($actRep[$r['report_id']])) continue;
    $rYear[$r['ref']] = $actRep[$r['report_id']];
    $rReport[$r['ref']] = $r['report_id'];
    $rRep[$r['ref']] = (int) $r['rep'];
    $rPrior[$r['ref']] = aero_first_prior($r['pr']);
    $rPriorYears[$r['ref']] = aero_prior_years($r['pr']);   // every year the auditor named — for documented depth
}
// inputs to the shared recurrence kernel (lib/Lineage.php). No non-repeat finding carries a
// prior in the data, so $rPrior is '' for them — which the kernel treats as "no prior".
$gMaps = ['refYear' => $rYear, 'prior' => $rPrior, 'rep' => $rRep,
          'priorYears' => $rPriorYears, 'windowStart' => $actRep ? min($actRep) : null];

$saStmt = $pdo->prepare(
    "SELECT fwa.report_id, fwa.reference_number ref, fwa.award_reference award_ref,
            fa.federal_agency_prefix prefix, al.agency_code code, fa.aln,
            fa.federal_program_name program, fa.amount_expended expended, fa.is_major
     FROM fac_finding_awards fwa
     JOIN fac_federal_awards fa ON fa.report_id=fwa.report_id AND fa.award_reference=fwa.award_reference
     LEFT JOIN assistance_listing al ON al.assistance_listing_id=fa.aln
     WHERE fwa.auditee_uei IN ($IN)"
);
$saStmt->execute($ueiSet);
$saByFinding = [];
foreach ($saStmt as $r) {
    $k = $r['report_id'] . '|' . $r['ref'];
    if (!isset($saByFinding[$k])) $saByFinding[$k] = ['subs' => [], 'alns' => [], 'awards' => []];
    $acr = $subAcr($r['prefix'], $r['code']);
    $saByFinding[$k]['subs'][$acr] = true;
    if ($r['aln']) $saByFinding[$k]['alns'][$r['aln']] = true;
    $saByFinding[$k]['awards'][$r['award_ref']] = [
        'award_ref' => $r['award_ref'], 'aln' => $r['aln'], 'program' => $r['program'],
        'sub_agency' => $acr, 'expended' => $r['expended'] !== null ? (float) $r['expended'] : 0.0,
        'is_major' => $r['is_major'] !== null ? (int) $r['is_major'] : null,
    ];
}
$QC_TRUSTED = ['known', 'generic', 'flagged', 'inline'];   // bases we treat as a real dollar figure
$filedYears = array_flip($actRep);   // distinct audit years the entity actually filed (active reports) — keys = years
$findings = array_map(function ($f) use ($saByFinding, $QC_TRUSTED, $gMaps, $rReport, $qcPreview, $rYear, $rPrior, $rRep, $rPriorYears, $filedYears) {
    $sa = $saByFinding[$f['report_id'] . '|' . $f['ref']] ?? ['subs' => [], 'alns' => [], 'awards' => []];
    $trusted = in_array($f['qc_basis'], $QC_TRUSTED, true) && (int) $f['qc_amount'] > 0;
    $isRep = (int) $f['rpt'] === 1;
    // recurrence depth, documented depth, the gap flag, and the out-of-window prior all come
    // from the one shared kernel (lib/Lineage.php). The chain timeline (with report_ids) is
    // rebuilt from its verified year->ref map.
    $lin = Lineage::walk($f['ref'], $gMaps);
    $chain = [];
    foreach ($lin['verified'] as $yy => $rf) {
        $chain[] = ['ref' => $rf, 'year' => $yy, 'report_id' => $rReport[$rf] ?? null];
    }
    $recur = 1; $gap = false; $priorOow = null; $doc = 1; $docFrom = null;
    if ($isRep) {
        $recur = $lin['traced_depth'];
        // the unloadable prior the deepest node cites (the +1 beyond our data window) — for
        // the UI chip that shows the year the count reaches but the loaded chain can't.
        $priorOow = ($lin['break'] && in_array($lin['break']['reason'], ['before_window', 'unresolved_ref'], true))
            ? $lin['break']['prior_ref'] : null;
        // non-consecutive ("gapped") recurrence: the chain skips an audit year the entity
        // actually filed within our window — a lapse-and-return pattern. Neutral marker only.
        $ly = $lin['loaded_years'];
        if (count($ly) >= 2) {
            $yset = array_flip($ly);
            for ($y = min($ly) + 1, $hi = max($ly); $y < $hi; $y++) {
                if (isset($filedYears[$y]) && !isset($yset[$y])) { $gap = true; break; }
            }
        }
        $doc = $lin['documented_depth'];
        $docFrom = $lin['documented_years'] ? min($lin['documented_years']) : null;
    }
    return [
        'report_id' => $f['report_id'], 'ref' => $f['ref'], 'year' => (int) $f['audit_year'],
        'type' => $f['type'],
        'mw' => (int) $f['mw'], 'sd' => (int) $f['sd'], 'mo' => (int) $f['mo'],
        'qc' => (int) $f['qc'], 'repeat' => (int) $f['rpt'], 'other' => (int) $f['other_fm'],
        'recur' => $recur, 'gap' => $gap, 'prior_oow' => $priorOow, 'doc' => $doc, 'doc_from' => $docFrom, 'chain' => $chain,
        // full recurrence lineage (verified/lapse/lookback/documented timeline) for the Chain tab's
        // rich viz — only for repeats; same shared builder as the finding-detail page.
        'lineage' => $isRep ? Lineage::nodes($f['ref'], $gMaps, $filedYears) : null,
        'qc_dollars' => $trusted ? (int) $f['qc_amount'] : 0,
        'qc_basis' => $f['qc_basis'],
        // the auditor's full QC narrative when there's no extracted $ (shown verbatim in
        // the QC tab's "as stated" column — no preview truncation here)
        'qc_text' => (!$trusted && trim((string) $f['qc_text']) !== '') ? trim($f['qc_text']) : null,
        'cap' => (int) $f['has_cap'],
        'subs' => array_keys($sa['subs']), 'alns' => array_keys($sa['alns']),
        'award_list' => array_values($sa['awards']),
    ];
}, $findingsRaw);

// counts for the tab strip
$awCntStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM fac_federal_awards fa
     JOIN fac_general g ON g.report_id = fa.report_id AND g.is_active = 1
     WHERE fa.auditee_uei IN ($IN)"
);
$awCntStmt->execute($ueiSet);
$counts = ['audits' => count($audits), 'awards' => (int) $awCntStmt->fetchColumn(), 'findings' => $findCum['total'], 'exclusions' => count($exclusions)];

// what-needs-attention — finding counts from the LATEST audit year only (the active,
// deduped report), so the cards reflect what is currently open, not an all-years sum.
$ftByYear = [];
foreach ($findingTrends as $t) $ftByYear[$t['year']] = $t;
$latestFt = ($facLatestYear !== null && isset($ftByYear[$facLatestYear])) ? $ftByYear[$facLatestYear] : [];
$attention = [
    'repeat_findings'    => (int) ($latestFt['repeat'] ?? 0),
    'material_weakness'  => (int) ($latestFt['mw'] ?? 0),
    'modified_opinion'   => (int) ($latestFt['modified'] ?? 0),
    'questioned_costs'   => (int) ($latestFt['qc'] ?? 0),
    'delinquent_audits'  => $delinquentAudits,   // Evaluation Level 1: missing-overdue (not filed) years
];

// KPI tiles
$cap = $drivers['cap_quality'] ?? [];
$kpis = [
    'federal_latest' => $federalLatest !== null ? (float) $federalLatest : null,
    'findings'       => ['total' => $findCum['total'], 'mw' => $findCum['mw'], 'repeat' => $findCum['repeat']],
    'cap'            => [
        'coverage' => $cap['coverage'] ?? null,
        'quality'  => $cap['quality'] ?? null,
    ],
];

// --- Evaluation: 7-level enforcement-triage framework, this entity's LATEST-audit
// counts. Levels 5/6/7 split the latest audit's findings by repeat lineage (2+ yr /
// 1st-yr / new); L2/L3/L4 are flag counts on the latest audit.
$evalL5 = 0; $evalL6 = 0; $evalL7 = 0; $evalTotal = 0; $latestClass = [];
if ($facLatestYear !== null) {
    // Reuse the lineage maps built for the Findings tab above ($actRep/$rYear/$rPrior/
    // $rRep) instead of re-running the same two queries — this block previously
    // duplicated them verbatim, doubling the per-finding scans on large entities.
    $activeRep = $actRep;
    $latestRefs = [];
    foreach ($rYear as $ref => $y) {
        if ($y === $facLatestYear) $latestRefs[$ref] = (($rRep[$ref] ?? 0) === 1) ? 1 : 0;   // classify the latest audit only
    }
    foreach ($latestRefs as $ref => $isRep) {
        $evalTotal++;
        if (!$isRep) { $evalL7++; $latestClass[$ref] = ['bucket' => 7, 'years' => 1]; continue; }
        // same shared kernel as the Findings tab + the Evaluation route (one definition of depth)
        $depth = Lineage::walk($ref, $gMaps)['traced_depth'];
        if ($depth >= 3) { $evalL5++; $latestClass[$ref] = ['bucket' => 5, 'years' => $depth]; }
        else { $evalL6++; $latestClass[$ref] = ['bucket' => 6, 'years' => $depth]; }
    }
}

// Per-level drill-down: which findings drive each level (latest audit only), the
// latest-year questioned-cost dollars, and the lineage/CAP detail. Built from the active
// report for the latest year so it matches the level counts (no resubmission dupes).
$latestReportId = ($facLatestYear !== null && isset($activeRep)) ? array_search($facLatestYear, $activeRep, true) : false;
$drill = [2 => [], 3 => [], 4 => [], 5 => [], 6 => [], 7 => []];
$qcDollars = 0; $qcCount = 0; $qcUnq = [];   // $qcUnq: FAC flagged QC but no extracted $ (stated-zeros excluded)
foreach ($findings as $f) {
    if ($latestReportId === false || $f['report_id'] !== $latestReportId) continue;
    $item = [
        'ref' => $f['ref'], 'report_id' => $f['report_id'], 'type' => $f['type'], 'cap' => $f['cap'],
        'subs' => $f['subs'], 'alns' => $f['alns'],
        'mw' => $f['mw'], 'sd' => $f['sd'], 'mo' => $f['mo'], 'qc' => $f['qc'], 'repeat' => $f['repeat'], 'other' => $f['other'],
        'recur' => $f['recur'], 'qc_dollars' => $f['qc_dollars'],
    ];
    if ($f['mo']) $drill[2][] = $item;
    if ($f['mw']) $drill[3][] = $item;
    // drill[4] carries EVERY latest-year finding with extracted QC dollars — that set IS
    // Level 4 now (any extracted questioned costs trigger it; no per-finding $ threshold)
    if ($f['qc_dollars'] > 0) { $qcDollars += $f['qc_dollars']; $qcCount++; $drill[4][] = $item; }
    elseif ($f['qc'] === 1 && $f['qc_basis'] !== 'zero') $qcUnq[] = $item;
    $cls = $latestClass[$f['ref']] ?? null;
    if ($cls) $drill[$cls['bucket']][] = $item;
}
$qcSignificant = $qcDollars > 0;
// (delinquency — $delinqLate / $delinqMissing / $delinqMissingUnverified — is computed
//  once above, near the attention card, so both surfaces report the same Level 1 count.)
$evaluation = [
    'latest_year'           => $facLatestYear,
    'latest_total_findings' => $evalTotal,
    'delinquent'            => ['late' => $delinqLate, 'missing' => $delinqMissing, 'missing_unverified' => $delinqMissingUnverified, 'years' => $delinqYears],
    'qc'                    => ['dollars' => $qcDollars, 'count' => $qcCount, 'significant' => $qcSignificant, 'unquantified' => $qcUnq],
    'drill'                 => $drill,
    'levels' => [
        1 => $delinqMissing,                  // not-filed years only (late filings = reference)
        2 => count($drill[2]),
        3 => count($drill[3]),
        4 => count($drill[4]),                // findings with extracted questioned-cost dollars
        5 => $evalL5,
        6 => $evalL6,
        7 => $evalL7,
    ],
];

json_out([
    'identity'         => $identity,
    'cognizant'        => $cognizant,
    'sam'              => $sam,
    'exclusions'       => $exclusions,
    'audits'           => $audits,
    'findings_summary' => $findingsSummary,
    'finding_trends'   => $findingTrends,
    'awards'           => $awards,
    'awards_obligated' => $awardsObligated,
    'findings_list'    => $findings,
    'fac_profile'      => $facProfile,
    'programs'         => $programs,
    'usa_synced'       => $usaSynced,
    'usa_last_synced'  => $usaLastSynced ?: null,
    'usa_award_count'  => $usaAwardCount,
    'usa_obligation_total' => $usaObligationTotal,
    'usa_latest_period_end' => $usaLatestPeriodEnd,
    'score'            => $score,
    'attention'        => $attention,
    'kpis'             => $kpis,
    'hhs_footprint'    => $hhsFootprint,
    'counts'           => $counts,
    'evaluation'       => $evaluation,
]);
