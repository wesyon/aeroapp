<?php
declare(strict_types=1);

// Guarded plain functions, so requiring it here is safe whether the caller is the CLI builder
// (which loads only Env/Db/Signals) or the route front controller (which already loaded it).
require_once __DIR__ . '/Rules.php';
require_once __DIR__ . '/UeiGroups.php';   // aero_uei_groups — collapse state_uei successions

/**
 * AERO Signals — anomaly & integrity indicator kernel.
 *
 * Canonical definition of every signal (the REGISTRY) plus the detector that finds each one.
 * Mirrors lib/Score.php: the rules live here, in git, not in the builder.
 * api/sync/build_signals.php iterates the implemented signals and writes hits to signal_flag.
 *
 * A detector returns a list of rows:
 *   ['uei' => CHAR(12), 'scope' => string, 'magnitude' => float|null, 'evidence' => array]
 * scope disambiguates multiple hits of one signal for one entity (report_id, counterparty UEI,
 * '' ). evidence is the CITED inputs behind the flag — stored verbatim, shown in the UI, so
 * explainability is structural: no evidence, no flag.
 *
 * Severity (drives the UI glyph): rule_violation | data_integrity | network | statistical.
 * 'context' = a broad concentration/quality lead that should NOT count toward convergence
 * ranking (it co-occurs too widely to be independent evidence); it still shows on the caseboard.
 * Thresholds are NAMED CONSTANTS so they can be tuned without touching SQL/schema.
 */
final class Signals
{
    // --- tunable thresholds -------------------------------------------------
    const PASSTHRU_IMPOSSIBLE_RATIO = 1.5;
    const PASSTHRU_IMPOSSIBLE_MIN   = 1_000_000;
    const CONDUIT_MIN_EXPENDED      = 5_000_000;
    const CONDUIT_LOW               = 0.98;
    const TOOCLEAN_MIN_AUDITS       = 40;
    const TOOCLEAN_MAX_RATE         = 0.02;
    const SELF_DEALING_MIN_ADDR_LEN = 10;
    const QC_RATIO_MIN              = 0.05;
    const QC_MIN_ABS                = 10_000;
    const CHRONIC_DEPTH             = 4;
    const BOUNDS_MAX                = 10_000_000_000_000;  // $10T — beyond any real single entity
    const LATE_MIN_YEARS            = 3;                   // chronic = late in >= this many years
    const CIRCULAR_MIN              = 25_000;              // each direction of a reciprocal flow over this $
    const SAM_LAPSE_MIN_FED         = 1_000_000;           // lapsed-SAM flag only above this federal $
    const RESUB_MIN_VERSION         = 2;                   // version>=2 => 3rd+ submission
    const CLUSTER_MIN               = 4;                   // CONTROL_CLUSTER min distinct UEIs
    const SHARED_CONTACT_MIN        = 3;                   // SHARED_CONTACT_NETWORK min distinct UEIs
    const ADDL_UEI_MIN              = 3;                   // IDENTIFIER_LAYERING min additional UEIs
    const DUP_MIN_REPEATS           = 5;                   // DUPLICATE_AMOUNT same figure N+ times in a report
    const NAME_DISTINCT_MIN         = 2;                   // NAME_VOLATILITY distinct normalized names
    const BURST_MIN_FEDERAL         = 5_000_000;           // BURST_VANISH: "large" last funded year
    const BURST_MIN_MISSING         = 2;                   // BURST_VANISH: gone for >= this many expected audits
    const SHOCK_MULT                = 5.0;                 // FUNDING_SHOCK YoY multiple
    const SHOCK_MIN_HI              = 1_000_000;           // ... with the higher year over this
    const NEWUEI_MAX_MONTHS         = 18;                  // NEW_UEI_FAST_MONEY reg->first-seen gap
    const NEWUEI_MIN_FED            = 5_000_000;

    /** tier 1|2|3 ; severity drives the glyph ; visibility internal|public ; implemented=detector exists ;
     *  context=excluded from convergence ranking. */
    const REGISTRY = [
        // ---- Tier 1: rule violations & impossible values -------------------
        'DEBAR_PIPELINE'          => ['tier' => 1, 'severity' => 'rule_violation', 'visibility' => 'internal', 'implemented' => true,
            'label' => 'Debarred party in the grant pipeline',
            'blurb' => 'An active grantee (with FAC presence) is on the active SAM exclusion list.'],
        'AUDITOR_NOT_INDEPENDENT' => ['tier' => 1, 'severity' => 'rule_violation', 'visibility' => 'internal', 'implemented' => true,
            'label' => 'Auditor operating from the client address',
            'blurb' => 'The audit firm address matches the auditee address — an independence impairment.'],
        'AUDITOR_CONTACT_SHARED'  => ['tier' => 1, 'severity' => 'rule_violation', 'visibility' => 'internal', 'implemented' => true,
            'label' => 'Auditor shares the client contact/email',
            'blurb' => 'Auditor and auditee share a contact name or email domain — a further independence impairment.'],
        'IMPOSSIBLE_PASSTHRU'     => ['tier' => 1, 'severity' => 'data_integrity', 'visibility' => 'internal', 'implemented' => true,
            'label' => 'Passed through more than received',
            'blurb' => 'Reported pass-through dollars exceed total federal dollars expended — mathematically impossible.'],
        'IMPOSSIBLE_QC'           => ['tier' => 1, 'severity' => 'data_integrity', 'visibility' => 'internal', 'implemented' => true,
            'label' => 'Questioned costs exceed total federal $',
            'blurb' => 'A finding\'s questioned-cost figure is larger than the entity\'s total federal expenditures.'],
        'IMPOSSIBLE_BOUNDS'       => ['tier' => 1, 'severity' => 'data_integrity', 'visibility' => 'internal', 'implemented' => true,
            'label' => 'Out-of-bounds value', 'blurb' => 'Negative or beyond-plausible amounts (e.g. above $10T).'],
        'DEBAR_INDIVIDUAL'        => ['tier' => 1, 'severity' => 'rule_violation', 'visibility' => 'internal', 'implemented' => true, 'context' => true,
            'label' => 'Excluded individual certifying a grantee', 'blurb' => 'A debarred person\'s name matches an auditee certifier (name-only match — needs identity corroboration to graduate).'],
        'EXCLUDED_SUBRECIPIENT'   => ['tier' => 1, 'severity' => 'rule_violation', 'visibility' => 'internal', 'implemented' => true,
            'label' => 'Federal money to a debarred subrecipient', 'blurb' => 'A subaward flows downstream to a party on the active SAM exclusion list.'],
        'SAM_LAPSED_FUNDED'       => ['tier' => 1, 'severity' => 'rule_violation', 'visibility' => 'internal', 'implemented' => true, 'context' => true,
            'label' => 'SAM registration lapsed while funded', 'blurb' => 'Registration inactive/expired for an entity with recent audited federal activity (sensitive to SAM cache freshness).'],
        'LATE_AUDIT_CHRONIC'      => ['tier' => 1, 'severity' => 'rule_violation', 'visibility' => 'internal', 'implemented' => true,
            'label' => 'Chronically late audit filing', 'blurb' => 'Audits repeatedly accepted past the 2 CFR 200.512 deadline (9 months after period end).'],
        'CERT_NO_REVIEW_WINDOW'   => ['tier' => 1, 'severity' => 'data_integrity', 'visibility' => 'internal', 'implemented' => true, 'context' => true,
            'label' => 'No audit review window', 'blurb' => 'The auditor certified before the auditee (common enough to be context, not a standalone flag).'],
        'RESUB_CHURN'             => ['tier' => 1, 'severity' => 'data_integrity', 'visibility' => 'internal', 'implemented' => true,
            'label' => 'Audit resubmitted repeatedly', 'blurb' => 'Three or more submission versions for one audit year.'],
        'MISSING_AUDIT_THRESHOLD' => ['tier' => 1, 'severity' => 'rule_violation', 'visibility' => 'internal', 'implemented' => true,
            'label' => 'Likely-required audit not filed',
            'blurb' => 'An expected Single Audit is past its 2 CFR 200.512 deadline and not on file, with federal award activity or reported expenditures confirming one was still required (Evaluation Level 1, lib/Rules.php). CAVEAT: an UNRECORDED UEI CHANGE looks identical — the old UEI stops filing while the successor files on. Measured 2026-07-15: ~12% of Level-1 entities have a sibling UEI (same EIN or name) that filed the very years called missing, so verify the recipient did not simply re-register before acting.'],
        // ---- Tier 2: network & convergence (gated on the affiliate allowlist)
        'SELF_DEALING'            => ['tier' => 2, 'severity' => 'network', 'visibility' => 'internal', 'implemented' => true,
            'label' => 'Money flow between co-controlled entities',
            'blurb' => 'A subaward runs between two entities sharing a certifier or address (pre-allowlist — expect legitimate affiliates).'],
        'AFFILIATE_SUBAWARD'      => ['tier' => 2, 'severity' => 'network', 'visibility' => 'internal', 'implemented' => true,
            'label' => 'Subaward to an affiliate / parent', 'blurb' => 'A subaward flows to an entity sharing a parent UEI — paying a related party.'],
        'CIRCULAR_FLOW'           => ['tier' => 2, 'severity' => 'network', 'visibility' => 'internal', 'implemented' => true, 'context' => true,
            'label' => 'Circular money flow', 'blurb' => 'A reciprocal A→B and B→A subaward relationship (legit-dominated pre-allowlist, like conduits).'],
        'CONDUIT'                 => ['tier' => 2, 'severity' => 'network', 'visibility' => 'internal', 'implemented' => true, 'context' => true,
            'label' => 'Pure conduit (≈100% pass-through)',
            'blurb' => 'Nearly all federal dollars flow straight through (pre-allowlist — expect legitimate CDFIs / revolving funds).'],
        'CONTROL_CLUSTER'         => ['tier' => 2, 'severity' => 'network', 'visibility' => 'internal', 'implemented' => true, 'context' => true,
            'label' => 'Shared-control cluster', 'blurb' => 'Many distinct UEIs share one certifier and address (concentration; feeds the allowlist).'],
        'SHARED_CONTACT_NETWORK'  => ['tier' => 2, 'severity' => 'network', 'visibility' => 'internal', 'implemented' => true, 'context' => true,
            'label' => 'Shared contact across entities', 'blurb' => 'Distinct UEIs share an email or phone — hidden common control beyond a shared address or certifier.'],
        'PHANTOM_SUB'             => ['tier' => 2, 'severity' => 'network', 'visibility' => 'internal', 'implemented' => false,
            'label' => 'Phantom subrecipient', 'blurb' => 'Money to a counterparty with no resolvable entity / no footprint (needs a fuller entity universe than our HHS-scoped cache).'],
        'DOWNSTREAM_RISK_CONC'    => ['tier' => 2, 'severity' => 'network', 'visibility' => 'internal', 'implemented' => false,
            'label' => 'Risk concentrated downstream', 'blurb' => 'A pass-through whose subrecipients are themselves unaudited, delinquent, or finding-heavy.'],
        'HUB_SPOKE'               => ['tier' => 2, 'severity' => 'network', 'visibility' => 'internal', 'implemented' => false,
            'label' => 'Hub-and-spoke', 'blurb' => 'One prime feeds many subs with minimal independent footprint.'],
        'REINCARNATION'           => ['tier' => 2, 'severity' => 'network', 'visibility' => 'internal', 'implemented' => false,
            'label' => 'Debarred identity reappears', 'blurb' => 'A debarred entity\'s name/address resurfaces under a new active UEI (needs a richer identity source than exclusions provide).'],
        // ---- Tier 3: statistical & behavioral (leads / context) ------------
        'TOO_CLEAN_AUDITOR'       => ['tier' => 3, 'severity' => 'statistical', 'visibility' => 'internal', 'implemented' => true, 'context' => true,
            'label' => 'Audited by a near-zero-finding firm',
            'blurb' => 'The entity\'s auditor runs a high-volume book with an improbably low finding rate.'],
        'QC_RATIO_HIGH'           => ['tier' => 3, 'severity' => 'statistical', 'visibility' => 'internal', 'implemented' => true,
            'label' => 'High questioned-cost ratio', 'blurb' => 'Questioned costs are a large share of the entity\'s federal expenditures.'],
        'REPEAT_FINDING_CHRONIC'  => ['tier' => 3, 'severity' => 'statistical', 'visibility' => 'internal', 'implemented' => true,
            'label' => 'Chronic uncorrected findings', 'blurb' => 'The same finding recurs across many years (willfulness; uses the lineage-depth kernel).'],
        'DISTRESS_STACK'          => ['tier' => 3, 'severity' => 'statistical', 'visibility' => 'internal', 'implemented' => true,
            'label' => 'Distress + weak-control stack', 'blurb' => 'Going concern and a material weakness co-occur (pressure x opportunity).'],
        'ADVERSE_OPINION'         => ['tier' => 3, 'severity' => 'statistical', 'visibility' => 'internal', 'implemented' => true, 'context' => true,
            'label' => 'Adverse / modified opinion', 'blurb' => 'A modified, adverse, or disclaimer audit opinion on the financial statements.'],
        'HIGH_RISK_REQUIREMENT'   => ['tier' => 3, 'severity' => 'statistical', 'visibility' => 'internal', 'implemented' => true, 'context' => true,
            'label' => 'High-fraud-risk compliance findings', 'blurb' => 'Findings in cash management, eligibility, allowable costs, procurement, or subrecipient monitoring.'],
        'LOW_RISK_CONTRADICTION'  => ['tier' => 3, 'severity' => 'statistical', 'visibility' => 'internal', 'implemented' => true,
            'label' => 'Low-risk label contradicted', 'blurb' => 'A self-designated low-risk auditee that nonetheless carries a material weakness.'],
        'FINDING_FRAUD_LANGUAGE'  => ['tier' => 3, 'severity' => 'statistical', 'visibility' => 'internal', 'implemented' => true,
            'label' => 'Fraud-adjacent finding language', 'blurb' => 'Finding text contains terms like misappropriation, falsified, kickback, theft, or personal use.'],
        'IDENTIFIER_LAYERING'     => ['tier' => 3, 'severity' => 'statistical', 'visibility' => 'internal', 'implemented' => true,
            'label' => 'Multiple EIN / UEI identifiers', 'blurb' => 'One entity reports several additional UEIs — an obfuscation / layering tell.'],
        'FOREIGN_FLOW'            => ['tier' => 3, 'severity' => 'statistical', 'visibility' => 'internal', 'implemented' => true,
            'label' => 'Foreign subrecipient', 'blurb' => 'Federal money flows to a foreign subrecipient (ties to the for-profit & foreign workstream).'],
        'FUNDING_SHOCK'           => ['tier' => 3, 'severity' => 'statistical', 'visibility' => 'internal', 'implemented' => true,
            'label' => 'Funding shock', 'blurb' => 'Year-over-year federal expenditures jump beyond a multiple.'],
        'DUPLICATE_AMOUNT'        => ['tier' => 3, 'severity' => 'statistical', 'visibility' => 'internal', 'implemented' => true,
            'label' => 'Repeated identical amounts', 'blurb' => 'The same exact dollar figure recurs across many awards in one audit (copy-paste budgeting / ghost transactions).'],
        'NAME_VOLATILITY'         => ['tier' => 3, 'severity' => 'statistical', 'visibility' => 'internal', 'implemented' => true, 'context' => true,
            'label' => 'Name churn', 'blurb' => 'The auditee legal name changes across years.'],
        'NEW_UEI_FAST_MONEY'      => ['tier' => 3, 'severity' => 'statistical', 'visibility' => 'internal', 'implemented' => true,
            'label' => 'New UEI, large money fast', 'blurb' => 'Short gap from SAM registration to a large audited federal program.'],
        'BENFORD_DEVIATION'       => ['tier' => 3, 'severity' => 'statistical', 'visibility' => 'internal', 'implemented' => false,
            'label' => 'Benford digit anomaly', 'blurb' => 'Amount leading-digit distribution deviates from Benford\'s law.'],
        'ROUND_NUMBER'            => ['tier' => 3, 'severity' => 'statistical', 'visibility' => 'internal', 'implemented' => false,
            'label' => 'Round-number density', 'blurb' => 'Unusually high share of amounts ending in 000.'],
        'THRESHOLD_HUG'           => ['tier' => 3, 'severity' => 'statistical', 'visibility' => 'internal', 'implemented' => false,
            'label' => 'Expenditures hug the audit threshold', 'blurb' => 'Total federal $ sits just below the single-audit line (needs the non-audited USAspending universe).'],
        'BURST_VANISH'            => ['tier' => 3, 'severity' => 'statistical', 'visibility' => 'internal', 'implemented' => true,
            'label' => 'Burst then vanish', 'blurb' => 'A large final audited year followed by silence — every expected audit since is confirmed missing, leaving that federal exposure unaudited.'],
        'SOURCE_DIVERGENCE'       => ['tier' => 3, 'severity' => 'statistical', 'visibility' => 'internal', 'implemented' => false,
            'label' => 'FAC ↔ USAspending divergence', 'blurb' => 'Expended (FAC) and obligated (USAspending) disagree sharply.'],
        'GEO_MISMATCH'            => ['tier' => 3, 'severity' => 'statistical', 'visibility' => 'internal', 'implemented' => false,
            'label' => 'Geographic mismatch', 'blurb' => 'Activity far from the entity location.'],
        'PROGRAM_MISMATCH'        => ['tier' => 3, 'severity' => 'statistical', 'visibility' => 'internal', 'implemented' => false,
            'label' => 'Program / NAICS mismatch', 'blurb' => 'Entity industry does not match the funded program family.'],
        'PERIOD_END_SPIKE'        => ['tier' => 3, 'severity' => 'statistical', 'visibility' => 'internal', 'implemented' => false,
            'label' => 'Period-end spending spike', 'blurb' => 'Obligations concentrated in the final month of the period (needs usa_award_txn_month backfill).'],
        'VELOCITY_FLOWTHROUGH'    => ['tier' => 3, 'severity' => 'statistical', 'visibility' => 'internal', 'implemented' => false,
            'label' => 'Same-period flow-through', 'blurb' => 'Federal dollars arrive and leave within the same month — conduit velocity.'],
        'SAMPLE_SIZE_BOILERPLATE' => ['tier' => 3, 'severity' => 'statistical', 'visibility' => 'internal', 'implemented' => false,
            'label' => 'Boilerplate test sample', 'blurb' => 'An auditor uses a fixed transaction sample size regardless of entity scale.'],
        'TEXT_REUSE'              => ['tier' => 3, 'severity' => 'statistical', 'visibility' => 'internal', 'implemented' => false,
            'label' => 'Copy-paste CAP / response text', 'blurb' => 'Identical corrective-action or auditee-response text across unrelated entities.'],
    ];

    public static function implemented(): array
    {
        return array_keys(array_filter(self::REGISTRY, fn ($m) => $m['implemented']));
    }

    public static function detect(PDO $pdo, string $code): array
    {
        return match ($code) {
            'DEBAR_PIPELINE'          => self::debarPipeline($pdo),
            'AUDITOR_NOT_INDEPENDENT' => self::auditorNotIndependent($pdo),
            'AUDITOR_CONTACT_SHARED'  => self::auditorContactShared($pdo),
            'IMPOSSIBLE_PASSTHRU'     => self::impossiblePassthru($pdo),
            'IMPOSSIBLE_QC'           => self::impossibleQc($pdo),
            'IMPOSSIBLE_BOUNDS'       => self::impossibleBounds($pdo),
            'DEBAR_INDIVIDUAL'        => self::debarIndividual($pdo),
            'EXCLUDED_SUBRECIPIENT'   => self::excludedSubrecipient($pdo),
            'SAM_LAPSED_FUNDED'       => self::samLapsedFunded($pdo),
            'LATE_AUDIT_CHRONIC'      => self::lateAuditChronic($pdo),
            'MISSING_AUDIT_THRESHOLD' => self::missingAuditThreshold($pdo),
            'BURST_VANISH'            => self::burstVanish($pdo),
            'CERT_NO_REVIEW_WINDOW'   => self::certNoReviewWindow($pdo),
            'RESUB_CHURN'             => self::resubChurn($pdo),
            'SELF_DEALING'            => self::selfDealing($pdo),
            'AFFILIATE_SUBAWARD'      => self::affiliateSubaward($pdo),
            'CIRCULAR_FLOW'           => self::circularFlow($pdo),
            'CONDUIT'                 => self::conduit($pdo),
            'CONTROL_CLUSTER'         => self::controlCluster($pdo),
            'SHARED_CONTACT_NETWORK'  => self::sharedContactNetwork($pdo),
            'TOO_CLEAN_AUDITOR'       => self::tooCleanAuditor($pdo),
            'QC_RATIO_HIGH'           => self::qcRatioHigh($pdo),
            'REPEAT_FINDING_CHRONIC'  => self::repeatFindingChronic($pdo),
            'DISTRESS_STACK'          => self::distressStack($pdo),
            'ADVERSE_OPINION'         => self::adverseOpinion($pdo),
            'HIGH_RISK_REQUIREMENT'   => self::highRiskRequirement($pdo),
            'LOW_RISK_CONTRADICTION'  => self::lowRiskContradiction($pdo),
            'FINDING_FRAUD_LANGUAGE'  => self::findingFraudLanguage($pdo),
            'IDENTIFIER_LAYERING'     => self::identifierLayering($pdo),
            'FOREIGN_FLOW'            => self::foreignFlow($pdo),
            'FUNDING_SHOCK'           => self::fundingShock($pdo),
            'DUPLICATE_AMOUNT'        => self::duplicateAmount($pdo),
            'NAME_VOLATILITY'         => self::nameVolatility($pdo),
            'NEW_UEI_FAST_MONEY'      => self::newUeiFastMoney($pdo),
            default                   => [],
        };
    }

    // small helper: latest-active-report join clause (g aliased, joins entity e)
    private const LATEST = "JOIN entity e ON e.uei = g.auditee_uei AND g.audit_year = e.latest_audit_year AND g.is_active = 1";

    // ======================================================================
    // Tier 1
    // ======================================================================

    private static function debarPipeline(PDO $pdo): array
    {
        $sql = "SELECT e.uei, e.federal_latest, MIN(x.id) excl_id, MAX(x.excluding_agency_name) agency,
                       MAX(x.exclusion_program) program, MAX(x.exclusion_type) etype,
                       MIN(x.activate_date) activated, MAX(x.termination_date) terminates
                FROM entity e JOIN sam_exclusion x ON x.uei_sam = e.uei AND x.record_status = 'Active'
                WHERE e.latest_audit_year IS NOT NULL GROUP BY e.uei, e.federal_latest";
        $out = [];
        foreach ($pdo->query($sql) as $r) {
            $out[] = ['uei' => $r['uei'], 'scope' => '', 'magnitude' => (float) ($r['federal_latest'] ?? 0),
                'evidence' => ['agency' => $r['agency'], 'program' => $r['program'], 'exclusion_type' => $r['etype'],
                    'activated' => $r['activated'], 'terminates' => $r['terminates'], 'exclusion_id' => (int) $r['excl_id']]];
        }
        return $out;
    }

    private static function auditorNotIndependent(PDO $pdo): array
    {
        $sql = "SELECT auditee_uei uei, report_id, audit_year, auditor_firm_name firm,
                       auditee_address_line_1 addr, auditee_zip zip
                FROM fac_general
                WHERE is_active = 1 AND auditee_uei IS NOT NULL AND CHAR_LENGTH(auditee_address_line_1) > 6
                  AND UPPER(REGEXP_REPLACE(auditor_address_line_1,'[^A-Za-z0-9]','')) =
                      UPPER(REGEXP_REPLACE(auditee_address_line_1,'[^A-Za-z0-9]',''))
                  AND LEFT(auditor_zip,5) = LEFT(auditee_zip,5)
                  AND UPPER(COALESCE(auditor_firm_name,'')) NOT LIKE '%SELF%'";
        $out = [];
        foreach ($pdo->query($sql) as $r) {
            $out[] = ['uei' => $r['uei'], 'scope' => $r['report_id'], 'magnitude' => null,
                'evidence' => ['report_id' => $r['report_id'], 'audit_year' => (int) $r['audit_year'],
                    'auditor_firm' => $r['firm'], 'shared_address' => $r['addr'], 'zip' => $r['zip']]];
        }
        return $out;
    }

    private static function auditorContactShared(PDO $pdo): array
    {
        $free = "('gmail.com','yahoo.com','hotmail.com','aol.com','outlook.com','icloud.com')";
        $sql = "SELECT g.auditee_uei uei, g.report_id, g.audit_year, g.auditor_firm_name firm,
                       g.auditee_contact_name acn, g.auditor_contact_name aucn,
                       LOWER(SUBSTRING_INDEX(g.auditee_email,'@',-1)) adom,
                       LOWER(SUBSTRING_INDEX(g.auditor_email,'@',-1)) udom
                FROM fac_general g " . self::LATEST . "
                WHERE (CHAR_LENGTH(g.auditee_contact_name) > 5
                       AND UPPER(TRIM(g.auditee_contact_name)) = UPPER(TRIM(g.auditor_contact_name)))
                   OR (g.auditee_email LIKE '%@%' AND g.auditor_email LIKE '%@%'
                       AND LOWER(SUBSTRING_INDEX(g.auditee_email,'@',-1)) = LOWER(SUBSTRING_INDEX(g.auditor_email,'@',-1))
                       AND LOWER(SUBSTRING_INDEX(g.auditee_email,'@',-1)) NOT IN $free)";
        $out = [];
        foreach ($pdo->query($sql) as $r) {
            $sharedName = $r['acn'] && strcasecmp(trim((string) $r['acn']), trim((string) $r['aucn'])) === 0;
            $out[] = ['uei' => $r['uei'], 'scope' => $r['report_id'], 'magnitude' => null,
                'evidence' => ['auditor_firm' => $r['firm'], 'audit_year' => (int) $r['audit_year'],
                    'shared_contact' => $sharedName ? $r['acn'] : null,
                    'shared_email_domain' => (!$sharedName && $r['adom'] === $r['udom']) ? $r['adom'] : null]];
        }
        return $out;
    }

    private static function impossiblePassthru(PDO $pdo): array
    {
        $sql = "SELECT a.auditee_uei uei, e.latest_audit_year yr, SUM(a.amount_expended) expended,
                       SUM(a.passthrough_amount) passed
                FROM fac_federal_awards a JOIN entity e ON e.uei = a.auditee_uei AND a.audit_year = e.latest_audit_year
                GROUP BY a.auditee_uei, e.latest_audit_year
                HAVING expended > 0 AND passed > expended * ? AND passed > ?";
        $st = $pdo->prepare($sql);
        $st->execute([self::PASSTHRU_IMPOSSIBLE_RATIO, self::PASSTHRU_IMPOSSIBLE_MIN]);
        $out = [];
        foreach ($st as $r) {
            $ratio = (float) $r['passed'] / max(1, (float) $r['expended']);
            $out[] = ['uei' => $r['uei'], 'scope' => '', 'magnitude' => round($ratio, 1),
                'evidence' => ['audit_year' => (int) $r['yr'], 'expended' => (float) $r['expended'],
                    'passed_through' => (float) $r['passed'], 'ratio' => round($ratio, 2)]];
        }
        return $out;
    }

    private static function impossibleQc(PDO $pdo): array
    {
        $sql = "SELECT fe.auditee_uei uei, fe.report_id, fe.finding_ref_number ref, fe.qc_amount,
                       g.total_amount_expended total
                FROM fac_finding_extract fe JOIN fac_general g ON g.report_id = fe.report_id AND g.is_active = 1
                WHERE fe.qc_amount > g.total_amount_expended AND fe.qc_amount > 0 AND g.total_amount_expended > 0";
        $out = [];
        foreach ($pdo->query($sql) as $r) {
            $out[] = ['uei' => $r['uei'], 'scope' => $r['report_id'] . '|' . $r['ref'],
                'magnitude' => round((float) $r['qc_amount'] / max(1, (float) $r['total']), 1),
                'evidence' => ['report_id' => $r['report_id'], 'finding' => $r['ref'],
                    'questioned_costs' => (float) $r['qc_amount'], 'total_federal' => (float) $r['total']]];
        }
        return $out;
    }

    private static function impossibleBounds(PDO $pdo): array
    {
        $out = [];
        // report-level: negative or absurd total expended
        $st = $pdo->prepare("SELECT auditee_uei uei, report_id, total_amount_expended t
                             FROM fac_general WHERE is_active = 1 AND auditee_uei IS NOT NULL
                               AND ABS(total_amount_expended) > ?");
        $st->execute([self::BOUNDS_MAX]);
        foreach ($st as $r) {
            $out[] = ['uei' => $r['uei'], 'scope' => $r['report_id'] . '|G', 'magnitude' => (float) $r['t'],
                'evidence' => ['field' => 'total_amount_expended', 'value' => (float) $r['t'], 'report_id' => $r['report_id']]];
        }
        // award-level: negative/absurd expended or passthrough
        $st = $pdo->prepare("SELECT a.auditee_uei uei, a.report_id, a.award_reference ref,
                                    a.amount_expended ae, a.passthrough_amount pt
                             FROM fac_federal_awards a JOIN fac_general g ON g.report_id = a.report_id AND g.is_active = 1
                             WHERE a.auditee_uei IS NOT NULL
                               AND (ABS(a.amount_expended) > ? OR ABS(a.passthrough_amount) > ?)");
        $st->execute([self::BOUNDS_MAX, self::BOUNDS_MAX]);
        foreach ($st as $r) {
            $bad = (abs((float) $r['ae']) > self::BOUNDS_MAX) ? 'amount_expended' : 'passthrough_amount';
            $out[] = ['uei' => $r['uei'], 'scope' => $r['report_id'] . '|' . $r['ref'],
                'magnitude' => max(abs((float) $r['ae']), abs((float) $r['pt'])),
                'evidence' => ['field' => $bad, 'amount_expended' => (float) $r['ae'],
                    'passthrough' => (float) $r['pt'], 'award' => $r['ref']]];
        }
        return $out;
    }

    private static function debarIndividual(PDO $pdo): array
    {
        $pdo->exec("DROP TABLE IF EXISTS _excl_ind");
        $pdo->exec("CREATE TABLE _excl_ind (nkey VARCHAR(160), full_name VARCHAR(255), agency VARCHAR(255), KEY(nkey))
                    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // normalized 'FIRST LAST'; require a reasonably distinctive last name to curb common-name collisions
        $pdo->exec("INSERT INTO _excl_ind
            SELECT UPPER(TRIM(REGEXP_REPLACE(CONCAT(first_name,' ',last_name),'[^A-Za-z ]',''))),
                   TRIM(CONCAT(first_name,' ',last_name)), excluding_agency_name
            FROM sam_exclusion
            WHERE record_status = 'Active' AND classification_type = 'Individual'
              AND first_name IS NOT NULL AND last_name IS NOT NULL AND CHAR_LENGTH(last_name) >= 4");
        $sql = "SELECT g.auditee_uei uei, g.auditee_certify_name cert, MAX(x.full_name) matched, MAX(x.agency) agency
                FROM fac_general g " . self::LATEST . "
                JOIN _excl_ind x ON x.nkey = UPPER(TRIM(REGEXP_REPLACE(g.auditee_certify_name,'[^A-Za-z ]','')))
                WHERE CHAR_LENGTH(g.auditee_certify_name) >= 6
                GROUP BY g.auditee_uei, g.auditee_certify_name";
        $out = [];
        foreach ($pdo->query($sql) as $r) {
            $out[] = ['uei' => $r['uei'], 'scope' => '', 'magnitude' => null,
                'evidence' => ['certifier' => $r['cert'], 'matched_debarred_individual' => $r['matched'], 'excluding_agency' => $r['agency']]];
        }
        $pdo->exec("DROP TABLE IF EXISTS _excl_ind");
        return $out;
    }

    private static function excludedSubrecipient(PDO $pdo): array
    {
        $sql = "SELECT ed.prime_entity_uei prime, ed.sub_vendor_uei sub, MAX(ed.sub_name) sub_name,
                       SUM(ed.total_amount) amt, MAX(x.excluding_agency_name) agency,
                       MAX(x.exclusion_program) program, MIN(x.activate_date) activated
                FROM subaward_edge ed
                JOIN sam_exclusion x ON x.uei_sam = ed.sub_vendor_uei AND x.record_status = 'Active'
                WHERE ed.prime_entity_uei <> ed.sub_vendor_uei
                GROUP BY ed.prime_entity_uei, ed.sub_vendor_uei";
        $out = [];
        foreach ($pdo->query($sql) as $r) {
            $out[] = ['uei' => $r['prime'], 'scope' => $r['sub'], 'magnitude' => (float) $r['amt'],
                'evidence' => ['subrecipient' => $r['sub_name'], 'subrecipient_uei' => $r['sub'],
                    'amount' => (float) $r['amt'], 'exclusion_agency' => $r['agency'],
                    'exclusion_program' => $r['program'], 'debarred_since' => $r['activated']]];
        }
        return $out;
    }

    private static function samLapsedFunded(PDO $pdo): array
    {
        $sql = "SELECT e.uei, e.federal_latest, e.latest_audit_year, s.registration_status st,
                       s.registration_expiration_date exp
                FROM entity e JOIN sam_entity s ON s.uei = e.uei
                WHERE e.latest_audit_year IS NOT NULL AND e.federal_latest >= " . self::SAM_LAPSE_MIN_FED . "
                  AND ( (s.registration_status IS NOT NULL AND s.registration_status <> 'Active')
                     OR (s.registration_expiration_date IS NOT NULL
                         AND s.registration_expiration_date < DATE_SUB(CURDATE(), INTERVAL 1 YEAR)) )";
        $out = [];
        foreach ($pdo->query($sql) as $r) {
            $out[] = ['uei' => $r['uei'], 'scope' => '', 'magnitude' => (float) ($r['federal_latest'] ?? 0),
                'evidence' => ['registration_status' => $r['st'], 'expiration' => $r['exp'],
                    'latest_audit_year' => (int) $r['latest_audit_year'], 'federal_latest' => (float) ($r['federal_latest'] ?? 0)]];
        }
        return $out;
    }

    private static function lateAuditChronic(PDO $pdo): array
    {
        // 2 CFR 200.512: accepted after the deadline, in >= LATE_MIN_YEARS years.
        //
        // The deadline comes from aero_deadline9() (lib/Rules.php), NOT from SQL. This used to
        // test `fac_accepted_date > DATE_ADD(fy_end_date, INTERVAL 9 MONTH)`, which keeps the
        // day-of-month and so lands a day EARLY whenever the FY-end is a short month's last day:
        // a Feb-28 FYE is due Nov-30, but DATE_ADD said Nov-28. That mis-flagged 3,363 reports as
        // late and falsely accused 471 entities of chronic lateness (measured 2026-07-15). The
        // month-end semantics live in one tested place; don't re-derive them in SQL.
        $st = $pdo->query("SELECT auditee_uei uei, audit_year, fy_end_date fye, fac_accepted_date acc
                           FROM fac_general
                           WHERE is_active = 1 AND auditee_uei IS NOT NULL
                             AND fy_end_date IS NOT NULL AND fac_accepted_date IS NOT NULL");
        $late = [];                                   // uei => [count, latestYear]
        foreach ($st as $r) {
            if (strtotime((string) $r['acc']) <= aero_deadline9((string) $r['fye'])) continue;
            $u = $r['uei'];
            $y = (int) $r['audit_year'];
            if (!isset($late[$u])) $late[$u] = [0, $y];
            $late[$u][0]++;
            if ($y > $late[$u][1]) $late[$u][1] = $y;
        }
        $out = [];
        foreach ($late as $u => [$n, $latest]) {
            if ($n < self::LATE_MIN_YEARS) continue;
            $out[] = ['uei' => $u, 'scope' => '', 'magnitude' => (float) $n,
                'evidence' => ['late_submissions' => $n, 'latest_year' => $latest]];
        }
        return $out;
    }

    /**
     * Level-1 delinquency for every known entity, from the SAME walk the Evaluation, the profile,
     * the map precompute and Search use (lib/Rules.php aero_filing_status). One pass, memoised, so
     * MISSING_AUDIT_THRESHOLD and BURST_VANISH share it — and so neither can assert a delinquency
     * the rest of AERO declines to (a missing year counts only when award activity or the >= $2M
     * expenditure proxy confirms an audit was still required; otherwise it is 'unverified', which
     * is a lead, not a flag).
     *
     * UEI successions are collapsed via aero_uei_groups(): a retired member is skipped entirely and
     * its filings merged into the canonical member. Without that, a government that changed UEI gets
     * its OLD identity flagged "audit not filed" for the years it has been filing under the new one.
     *
     * @return array uei => ['missing' => [year => confirmed_by], 'last_filed' => ?int, 'federal' => float]
     */
    private static function delinquency(PDO $pdo): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $grp = aero_uei_groups($pdo);
        $members = $grp['canon'];        // canonical => [member ueis]  (FILINGS)
        $retired = $grp['retired'];      // retired => canonical

        // Component MONEY family (fac_additional_ueis UNION entity_related_uei): a parent's money
        // lands under its component agencies' own UEIs even though it files one consolidated audit.
        // The activity check rolls these up; FILINGS stay on the succession set. Matches
        // /api/evaluation and the map — without it a component-heavy entity looks inactive and only
        // the $2M proxy catches it (State of Nevada = $0 solo, $6.9B across its departments).
        $compOf = [];
        foreach ($pdo->query("SELECT auditee_uei uei, additional_uei m FROM fac_additional_ueis WHERE additional_uei > ''
                              UNION SELECT uei, related_uei m FROM entity_related_uei WHERE related_uei > ''") as $r) {
            $compOf[$r['uei']][] = $r['m'];
        }

        $fed = [];
        foreach ($pdo->query("SELECT uei, COALESCE(federal_latest, 0) f FROM entity
                              WHERE latest_audit_year IS NOT NULL") as $r) {
            $fed[$r['uei']] = (float) $r['f'];
        }
        $subjects = array_values(array_diff(array_keys($fed), array_keys($retired)));   // never judge a retired UEI
        $cache = [];
        foreach (array_chunk($subjects, 5000) as $chunk) {
            // look up the chunk PLUS retired siblings (for the succession history) AND component
            // agencies (for the money), so both families are fetched in one pass.
            $lookup = [];
            foreach ($chunk as $u) {
                foreach ($members[$u] ?? [$u] as $m) {
                    $lookup[$m] = true;
                    foreach ($compOf[$m] ?? [] as $c) $lookup[$c] = true;
                }
            }
            $lk = array_keys($lookup);
            $in = implode(',', array_fill(0, count($lk), '?'));
            $st = $pdo->prepare("SELECT auditee_uei uei, audit_year yr, MAX(fy_end_date) fy,
                                        MAX(audit_period_covered = 'biennial') bi
                                 FROM fac_general WHERE auditee_uei IN ($in) AND fy_end_date IS NOT NULL
                                 GROUP BY auditee_uei, audit_year");
            $st->execute($lk);
            $byUei = [];
            foreach ($st as $r) $byUei[$r['uei']][(int) $r['yr']] = ['fy' => $r['fy'], 'orig' => null, 'bi' => (int) $r['bi'] === 1];

            $iv = [];
            $st = $pdo->prepare("SELECT recipient_uei uei, period_start_date s, period_end_date e FROM usa_award
                                 WHERE recipient_uei IN ($in) AND category IN ('grant','direct_payment')
                                   AND period_start_date IS NOT NULL AND period_end_date IS NOT NULL");
            $st->execute($lk);
            foreach ($st as $r) $iv[$r['uei']][] = [$r['s'], $r['e']];
            $sy = [];
            try {
                $st = $pdo->prepare("SELECT sub_vendor_uei uei, year FROM subaward_edge WHERE sub_vendor_uei IN ($in)
                                     GROUP BY sub_vendor_uei, year");
                $st->execute($lk);
                foreach ($st as $r) $sy[$r['uei']][(int) $r['year']] = true;
            } catch (\Throwable $e) { /* no edge table -> proxy + direct awards still apply */ }

            foreach ($chunk as $uei) {
                $f = []; $ivM = []; $syM = [];
                foreach ($members[$uei] ?? [$uei] as $m) {
                    foreach ($byUei[$m] ?? [] as $yy => $x) { if (!isset($f[$yy])) $f[$yy] = $x; }   // FILINGS: succession
                    foreach ([$m, ...($compOf[$m] ?? [])] as $mm) {                                  // MONEY: + components
                        foreach ($iv[$mm] ?? [] as $pp) $ivM[] = $pp;
                        foreach ($sy[$mm] ?? [] as $yy => $_u) $syM[$yy] = true;
                    }
                }
                if (!$f) continue;
                $miss = [];
                foreach (aero_filing_status($f, aero_activity_confirmer($ivM, $syM), $fed[$uei] ?? 0.0) as $y => $s) {
                    if ($s['st'] === 'missing') $miss[$y] = $s['confirmed_by'];
                }
                if (!$miss) continue;
                $cache[$uei] = ['missing' => $miss, 'last_filed' => max(array_keys($f)), 'federal' => $fed[$uei] ?? 0.0];
            }
        }
        return $cache;
    }

    private static function missingAuditThreshold(PDO $pdo): array
    {
        $out = [];
        foreach (self::delinquency($pdo) as $uei => $d) {
            $years = array_keys($d['missing']);
            sort($years);
            $out[] = ['uei' => $uei, 'scope' => '', 'magnitude' => (float) count($years),
                'evidence' => [
                    'missing_years'   => $years,
                    'confirmed_by'    => array_values(array_unique(array_values($d['missing']))),
                    'last_filed_year' => $d['last_filed'],
                    'federal_latest'  => round($d['federal'], 2),
                ]];
        }
        return $out;
    }

    private static function burstVanish(PDO $pdo): array
    {
        // Large last funded year, then gone: every expected audit since the final filing is
        // confirmed-missing. A strict subset of MISSING_AUDIT_THRESHOLD, qualified by money and
        // persistence — the point is the exposure left unaudited, not the filing gap itself.
        $out = [];
        foreach (self::delinquency($pdo) as $uei => $d) {
            if ($d['federal'] < self::BURST_MIN_FEDERAL) continue;
            $years = array_keys($d['missing']);
            if (count($years) < self::BURST_MIN_MISSING) continue;
            sort($years);
            // "vanished" = the missing run starts right after the last filing and never resumes
            if ($d['last_filed'] === null || $years[0] !== $d['last_filed'] + 1) continue;
            $out[] = ['uei' => $uei, 'scope' => '', 'magnitude' => round($d['federal'], 2),
                'evidence' => [
                    'last_filed_year'      => $d['last_filed'],
                    'federal_that_year'    => round($d['federal'], 2),
                    'missing_since'        => $years[0],
                    'consecutive_missing'  => count($years),
                    'confirmed_by'         => array_values(array_unique(array_values($d['missing']))),
                ]];
        }
        return $out;
    }

    private static function certNoReviewWindow(PDO $pdo): array
    {
        // auditor certifying BEFORE the auditee is out of order (same-day is common and not flagged)
        $sql = "SELECT g.auditee_uei uei, g.report_id, g.audit_year, g.auditee_certified_date acd, g.auditor_certified_date aucd
                FROM fac_general g " . self::LATEST . "
                WHERE g.auditee_certified_date IS NOT NULL AND g.auditor_certified_date IS NOT NULL
                  AND g.auditor_certified_date < g.auditee_certified_date";
        $out = [];
        foreach ($pdo->query($sql) as $r) {
            $out[] = ['uei' => $r['uei'], 'scope' => $r['report_id'], 'magnitude' => null,
                'evidence' => ['audit_year' => (int) $r['audit_year'], 'auditee_certified' => $r['acd'],
                    'auditor_certified' => $r['aucd'], 'note' => 'auditor certified before auditee']];
        }
        return $out;
    }

    private static function resubChurn(PDO $pdo): array
    {
        $sql = "SELECT auditee_uei uei, audit_year, MAX(version) maxv, COUNT(*) n
                FROM fac_resubmission WHERE auditee_uei IS NOT NULL
                GROUP BY auditee_uei, audit_year HAVING maxv >= ?";
        $st = $pdo->prepare($sql);
        $st->execute([self::RESUB_MIN_VERSION]);
        $out = [];
        foreach ($st as $r) {
            $out[] = ['uei' => $r['uei'], 'scope' => (string) $r['audit_year'], 'magnitude' => (float) $r['maxv'],
                'evidence' => ['audit_year' => (int) $r['audit_year'], 'submission_versions' => (int) $r['maxv'] + 1]];
        }
        return $out;
    }

    // ======================================================================
    // Tier 2
    // ======================================================================

    private static function selfDealing(PDO $pdo): array
    {
        self::buildEntId($pdo);
        $minLen = self::SELF_DEALING_MIN_ADDR_LEN;
        $sql = "SELECT ed.prime_entity_uei prime, ed.sub_vendor_uei sub, ed.prime_name, ed.sub_name,
                       ed.year, ed.total_amount,
                       (a.certkey = b.certkey AND a.certkey <> '') AS same_cert,
                       (a.addrkey = b.addrkey AND CHAR_LENGTH(a.addrkey) > $minLen AND a.addrkey NOT LIKE '|%') AS same_addr,
                       a.certkey
                FROM subaward_edge ed
                JOIN _sig_entid a ON a.uei = ed.prime_entity_uei
                JOIN _sig_entid b ON b.uei = ed.sub_vendor_uei
                WHERE ed.prime_entity_uei <> ed.sub_vendor_uei
                  AND ((a.certkey = b.certkey AND a.certkey <> '')
                    OR (a.addrkey = b.addrkey AND CHAR_LENGTH(a.addrkey) > $minLen AND a.addrkey NOT LIKE '|%'))";
        $out = [];
        foreach ($pdo->query($sql) as $r) {
            $shared = $r['same_cert'] ? 'certifier' : 'address';
            $base = ['shared' => $shared, 'year' => (int) $r['year'], 'amount' => (float) $r['total_amount'],
                'certifier' => $r['same_cert'] ? $r['certkey'] : null];
            $out[] = ['uei' => $r['prime'], 'scope' => $r['sub'], 'magnitude' => (float) $r['total_amount'],
                'evidence' => $base + ['role' => 'prime (payer)', 'counterparty' => $r['sub_name'], 'counterparty_uei' => $r['sub']]];
            $out[] = ['uei' => $r['sub'], 'scope' => $r['prime'], 'magnitude' => (float) $r['total_amount'],
                'evidence' => $base + ['role' => 'sub (payee)', 'counterparty' => $r['prime_name'], 'counterparty_uei' => $r['prime']]];
        }
        $pdo->exec("DROP TABLE IF EXISTS _sig_entid");
        return $out;
    }

    private static function affiliateSubaward(PDO $pdo): array
    {
        $sql = "SELECT ed.prime_entity_uei a, ed.sub_vendor_uei b, MAX(ed.sub_name) bn, SUM(ed.total_amount) amt,
                       rp.parent_uei pp, rs.parent_uei sp
                FROM subaward_edge ed
                JOIN usa_recipient rp ON rp.uei = ed.prime_entity_uei
                JOIN usa_recipient rs ON rs.uei = ed.sub_vendor_uei
                WHERE ed.prime_entity_uei <> ed.sub_vendor_uei
                  AND ( (rp.parent_uei IS NOT NULL AND rp.parent_uei = rs.parent_uei)
                     OR rp.parent_uei = ed.sub_vendor_uei OR rs.parent_uei = ed.prime_entity_uei )
                GROUP BY ed.prime_entity_uei, ed.sub_vendor_uei, rp.parent_uei, rs.parent_uei";
        $out = [];
        foreach ($pdo->query($sql) as $r) {
            $rel = $r['pp'] !== null && $r['pp'] === $r['sp'] ? 'shared parent ' . $r['pp']
                : ($r['pp'] === $r['b'] ? 'sub is parent' : 'prime is parent');
            $out[] = ['uei' => $r['a'], 'scope' => $r['b'], 'magnitude' => (float) $r['amt'],
                'evidence' => ['counterparty' => $r['bn'], 'counterparty_uei' => $r['b'],
                    'relationship' => $rel, 'amount' => (float) $r['amt']]];
        }
        return $out;
    }

    private static function circularFlow(PDO $pdo): array
    {
        // reciprocal A→B and B→A, each direction over CIRCULAR_MIN (filters trivial cross-subawards)
        $sql = "SELECT p.a, p.b, p.an, p.bn, p.amt ab, q.amt ba
                FROM (SELECT prime_entity_uei a, sub_vendor_uei b, MAX(prime_name) an, MAX(sub_name) bn, SUM(total_amount) amt
                      FROM subaward_edge GROUP BY prime_entity_uei, sub_vendor_uei) p
                JOIN (SELECT prime_entity_uei a, sub_vendor_uei b, SUM(total_amount) amt
                      FROM subaward_edge GROUP BY prime_entity_uei, sub_vendor_uei) q
                  ON q.a = p.b AND q.b = p.a
                WHERE p.a < p.b AND p.amt >= ? AND q.amt >= ?";
        $st = $pdo->prepare($sql);
        $st->execute([self::CIRCULAR_MIN, self::CIRCULAR_MIN]);
        $out = [];
        foreach ($st as $r) {
            $out[] = ['uei' => $r['a'], 'scope' => $r['b'], 'magnitude' => (float) $r['ab'],
                'evidence' => ['reciprocal_with' => $r['bn'], 'counterparty_uei' => $r['b'],
                    'sent' => (float) $r['ab'], 'received' => (float) $r['ba']]];
            $out[] = ['uei' => $r['b'], 'scope' => $r['a'], 'magnitude' => (float) $r['ba'],
                'evidence' => ['reciprocal_with' => $r['an'], 'counterparty_uei' => $r['a'],
                    'sent' => (float) $r['ba'], 'received' => (float) $r['ab']]];
        }
        return $out;
    }

    private static function conduit(PDO $pdo): array
    {
        $sql = "SELECT a.auditee_uei uei, e.latest_audit_year yr, SUM(a.amount_expended) expended,
                       SUM(a.passthrough_amount) passed
                FROM fac_federal_awards a JOIN entity e ON e.uei = a.auditee_uei AND a.audit_year = e.latest_audit_year
                GROUP BY a.auditee_uei, e.latest_audit_year
                HAVING expended > ? AND passed / expended BETWEEN ? AND ?";
        $st = $pdo->prepare($sql);
        $st->execute([self::CONDUIT_MIN_EXPENDED, self::CONDUIT_LOW, self::PASSTHRU_IMPOSSIBLE_RATIO]);
        $out = [];
        foreach ($st as $r) {
            $ratio = (float) $r['passed'] / max(1, (float) $r['expended']);
            $out[] = ['uei' => $r['uei'], 'scope' => '', 'magnitude' => (float) $r['expended'],
                'evidence' => ['audit_year' => (int) $r['yr'], 'expended' => (float) $r['expended'],
                    'passed_through' => (float) $r['passed'], 'ratio' => round($ratio, 3)]];
        }
        return $out;
    }

    private static function controlCluster(PDO $pdo): array
    {
        $pdo->exec("DROP TABLE IF EXISTS _cc");
        $pdo->exec("CREATE TABLE _cc (uei CHAR(12), cert VARCHAR(255), addr VARCHAR(120), gkey VARCHAR(360), KEY(gkey))
                    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("INSERT INTO _cc
            SELECT uei, cert, addr, CONCAT(cert,'@@',addr) FROM (
              SELECT g.auditee_uei uei, UPPER(TRIM(g.auditee_certify_name)) cert,
                     CONCAT(UPPER(REGEXP_REPLACE(g.auditee_address_line_1,'[^A-Za-z0-9]','')),'|',LEFT(g.auditee_zip,5)) addr,
                     ROW_NUMBER() OVER (PARTITION BY g.auditee_uei ORDER BY g.audit_year DESC) rn
              FROM fac_general g
              WHERE g.is_active = 1 AND g.auditee_uei IS NOT NULL
                AND TRIM(g.auditee_certify_name) <> '' AND CHAR_LENGTH(g.auditee_address_line_1) > 6
            ) z WHERE rn = 1");
        $st = $pdo->prepare(
            "SELECT c.uei, c.cert, c.addr, gc.n
             FROM _cc c JOIN (SELECT gkey, COUNT(*) n FROM _cc GROUP BY gkey HAVING n >= ?) gc ON gc.gkey = c.gkey");
        $st->execute([self::CLUSTER_MIN]);
        $out = [];
        foreach ($st as $r) {
            $out[] = ['uei' => $r['uei'], 'scope' => '', 'magnitude' => (float) $r['n'],
                'evidence' => ['cluster_size' => (int) $r['n'], 'shared_certifier' => $r['cert'], 'shared_address' => $r['addr']]];
        }
        $pdo->exec("DROP TABLE IF EXISTS _cc");
        return $out;
    }

    private static function sharedContactNetwork(PDO $pdo): array
    {
        $pdo->exec("DROP TABLE IF EXISTS _sc");
        $pdo->exec("CREATE TABLE _sc (uei CHAR(12), ckey VARCHAR(180), kind VARCHAR(6), KEY(ckey))
                    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("INSERT INTO _sc SELECT uei, ckey, 'email' FROM (
              SELECT g.auditee_uei uei, CONCAT('E:',LOWER(TRIM(g.auditee_email))) ckey,
                     ROW_NUMBER() OVER (PARTITION BY g.auditee_uei ORDER BY g.audit_year DESC) rn
              FROM fac_general g " . self::LATEST . " WHERE g.auditee_email LIKE '%@%'
            ) z WHERE rn = 1");
        $pdo->exec("INSERT INTO _sc SELECT uei, ckey, 'phone' FROM (
              SELECT g.auditee_uei uei, CONCAT('P:',REGEXP_REPLACE(g.auditee_phone,'[^0-9]','')) ckey,
                     ROW_NUMBER() OVER (PARTITION BY g.auditee_uei ORDER BY g.audit_year DESC) rn
              FROM fac_general g " . self::LATEST . " WHERE CHAR_LENGTH(REGEXP_REPLACE(g.auditee_phone,'[^0-9]','')) >= 10
            ) z WHERE rn = 1");
        $st = $pdo->prepare(
            "SELECT c.uei, c.ckey, c.kind, gc.n
             FROM _sc c JOIN (SELECT ckey, COUNT(DISTINCT uei) n FROM _sc GROUP BY ckey HAVING n >= ?) gc ON gc.ckey = c.ckey");
        $st->execute([self::SHARED_CONTACT_MIN]);
        $out = [];
        foreach ($st as $r) {
            $out[] = ['uei' => $r['uei'], 'scope' => $r['ckey'], 'magnitude' => (float) $r['n'],
                'evidence' => ['shared_via' => $r['kind'], 'shared_value' => substr($r['ckey'], 2), 'group_size' => (int) $r['n']]];
        }
        $pdo->exec("DROP TABLE IF EXISTS _sc");
        return $out;
    }

    // ======================================================================
    // Tier 3
    // ======================================================================

    private static function tooCleanAuditor(PDO $pdo): array
    {
        $auditors = $pdo->prepare(
            "SELECT g.auditor_ein, MAX(g.auditor_firm_name) firm, COUNT(DISTINCT g.report_id) audits,
                    SUM(CASE WHEN f.fcount > 0 THEN 1 ELSE 0 END) / COUNT(DISTINCT g.report_id) rate
             FROM fac_general g
             LEFT JOIN (SELECT report_id, COUNT(*) fcount FROM fac_findings GROUP BY report_id) f ON f.report_id = g.report_id
             WHERE g.is_active = 1 AND g.audit_year >= 2022 AND g.auditor_ein IS NOT NULL
             GROUP BY g.auditor_ein HAVING audits >= ? AND rate <= ?");
        $auditors->execute([self::TOOCLEAN_MIN_AUDITS, self::TOOCLEAN_MAX_RATE]);
        $meta = [];
        foreach ($auditors as $a) $meta[$a['auditor_ein']] = $a;
        if (!$meta) return [];
        $ph = implode(',', array_fill(0, count($meta), '?'));
        $clients = $pdo->prepare(
            "SELECT g.auditee_uei uei, g.auditor_ein FROM fac_general g
             JOIN entity e ON e.uei = g.auditee_uei AND g.audit_year = e.latest_audit_year AND g.is_active = 1
             WHERE g.auditor_ein IN ($ph)");
        $clients->execute(array_keys($meta));
        $out = [];
        foreach ($clients as $r) {
            $m = $meta[$r['auditor_ein']];
            $out[] = ['uei' => $r['uei'], 'scope' => $r['auditor_ein'], 'magnitude' => (float) $m['audits'],
                'evidence' => ['auditor_ein' => $r['auditor_ein'], 'auditor_firm' => $m['firm'],
                    'auditor_audits' => (int) $m['audits'], 'finding_rate' => round((float) $m['rate'], 3)]];
        }
        return $out;
    }

    private static function qcRatioHigh(PDO $pdo): array
    {
        $sql = "SELECT g.auditee_uei uei, g.report_id, g.audit_year yr, g.total_amount_expended total, SUM(fe.qc_amount) qc
                FROM fac_general g " . self::LATEST . "
                JOIN fac_finding_extract fe ON fe.report_id = g.report_id AND fe.qc_amount > 0
                GROUP BY g.auditee_uei, g.report_id, g.audit_year, g.total_amount_expended
                HAVING total > 0 AND qc >= ? AND qc / total >= ?";
        $st = $pdo->prepare($sql);
        $st->execute([self::QC_MIN_ABS, self::QC_RATIO_MIN]);
        $out = [];
        foreach ($st as $r) {
            $ratio = (float) $r['qc'] / max(1, (float) $r['total']);
            $out[] = ['uei' => $r['uei'], 'scope' => $r['report_id'], 'magnitude' => round($ratio * 100, 1),
                'evidence' => ['questioned_costs' => (float) $r['qc'], 'total_federal' => (float) $r['total'],
                    'qc_pct_of_federal' => round($ratio * 100, 1), 'audit_year' => (int) $r['yr']]];
        }
        return $out;
    }

    private static function repeatFindingChronic(PDO $pdo): array
    {
        require_once dirname(__DIR__) . '/lib/Rules.php';
        require_once dirname(__DIR__) . '/lib/Lineage.php';
        $activeRep = [];
        foreach ($pdo->query("SELECT auditee_uei uei, report_id, audit_year yr FROM fac_general WHERE is_active=1 AND auditee_uei IS NOT NULL") as $r) {
            $activeRep[$r['uei']][$r['report_id']] = (int) $r['yr'];
        }
        $fByUei = [];
        foreach ($pdo->query(
            "SELECT f.auditee_uei uei, f.report_id, f.reference_number ref, f.audit_year yr,
                    f.is_repeat_finding rep, f.prior_finding_ref_numbers pr
             FROM fac_findings f JOIN fac_general g ON g.report_id = f.report_id AND g.is_active = 1
             WHERE f.auditee_uei IS NOT NULL") as $r) {
            $fByUei[$r['uei']][] = $r;
        }
        $out = [];
        foreach ($fByUei as $uei => $fnd) {
            $reps = $activeRep[$uei] ?? [];
            if (!$reps) continue;
            $latestYear = max($reps);
            $latestRid = array_search($latestYear, $reps, true);
            $refYear = []; $prior = []; $priorYears = []; $rep = [];
            foreach ($fnd as $f) {
                $refYear[$f['ref']] = (int) $f['yr'];
                $rep[$f['ref']] = (int) $f['rep'];
                if ((int) $f['rep'] === 1) {
                    $prior[$f['ref']] = aero_first_prior($f['pr']);
                    $priorYears[$f['ref']] = aero_prior_years($f['pr']);
                }
            }
            $maps = ['refYear' => $refYear, 'prior' => $prior, 'rep' => $rep, 'priorYears' => $priorYears, 'windowStart' => min($reps)];
            $maxDoc = 0; $maxTraced = 0; $chronic = 0; $deepRef = null; $deepYears = [];
            foreach ($fnd as $f) {
                if ($f['report_id'] !== $latestRid || (int) $f['rep'] !== 1) continue;
                $lin = Lineage::walk($f['ref'], $maps);
                if ($lin['documented_depth'] >= self::CHRONIC_DEPTH) $chronic++;
                if ($lin['documented_depth'] > $maxDoc) {
                    $maxDoc = $lin['documented_depth']; $maxTraced = $lin['traced_depth'];
                    $deepRef = $f['ref']; $deepYears = $lin['documented_years'];
                }
            }
            if ($maxDoc >= self::CHRONIC_DEPTH) {
                $out[] = ['uei' => $uei, 'scope' => '', 'magnitude' => (float) $maxDoc,
                    'evidence' => ['recurrence_years' => $maxDoc, 'traced_years' => $maxTraced, 'chronic_findings' => $chronic,
                        'latest_audit' => $latestYear, 'example_finding' => $deepRef,
                        'spans' => $deepYears ? (min($deepYears) . '–' . max($deepYears)) : null]];
            }
        }
        return $out;
    }

    private static function distressStack(PDO $pdo): array
    {
        $sql = "SELECT g.auditee_uei uei, g.audit_year, g.is_internal_control_material_weakness_disclosed mw
                FROM fac_general g " . self::LATEST . "
                WHERE g.is_going_concern_included = 1 AND g.is_internal_control_material_weakness_disclosed = 1";
        $out = [];
        foreach ($pdo->query($sql) as $r) {
            $out[] = ['uei' => $r['uei'], 'scope' => '', 'magnitude' => null,
                'evidence' => ['going_concern' => 'yes', 'material_weakness' => 'yes', 'audit_year' => (int) $r['audit_year']]];
        }
        return $out;
    }

    private static function adverseOpinion(PDO $pdo): array
    {
        $sql = "SELECT g.auditee_uei uei, g.audit_year, g.gaap_results op
                FROM fac_general g " . self::LATEST . "
                WHERE g.gaap_results IS NOT NULL
                  AND ( g.gaap_results LIKE '%Adverse%' OR g.gaap_results LIKE '%Disclaimer%'
                     OR (g.gaap_results LIKE '%Qualified%' AND g.gaap_results NOT LIKE '%Unqualified%') )";
        $out = [];
        foreach ($pdo->query($sql) as $r) {
            $out[] = ['uei' => $r['uei'], 'scope' => '', 'magnitude' => null,
                'evidence' => ['opinion' => $r['op'], 'audit_year' => (int) $r['audit_year']]];
        }
        return $out;
    }

    private static function highRiskRequirement(PDO $pdo): array
    {
        // B allowable costs, C cash mgmt, E eligibility, I procurement, M subrecipient monitoring
        $sql = "SELECT f.auditee_uei uei, GROUP_CONCAT(DISTINCT f.type_requirement ORDER BY f.type_requirement SEPARATOR ',') reqs, COUNT(*) n
                FROM fac_findings f
                JOIN entity e ON e.uei = f.auditee_uei AND f.audit_year = e.latest_audit_year
                JOIN fac_general g ON g.report_id = f.report_id AND g.is_active = 1
                WHERE f.type_requirement REGEXP '[BCEIM]'
                GROUP BY f.auditee_uei";
        $out = [];
        foreach ($pdo->query($sql) as $r) {
            $out[] = ['uei' => $r['uei'], 'scope' => '', 'magnitude' => (float) $r['n'],
                'evidence' => ['requirements' => $r['reqs'], 'findings' => (int) $r['n']]];
        }
        return $out;
    }

    private static function lowRiskContradiction(PDO $pdo): array
    {
        $sql = "SELECT g.auditee_uei uei, g.audit_year
                FROM fac_general g " . self::LATEST . "
                WHERE g.is_low_risk_auditee = 1 AND g.is_internal_control_material_weakness_disclosed = 1";
        $out = [];
        foreach ($pdo->query($sql) as $r) {
            $out[] = ['uei' => $r['uei'], 'scope' => '', 'magnitude' => null,
                'evidence' => ['self_designation' => 'low-risk auditee', 'but' => 'material weakness disclosed', 'audit_year' => (int) $r['audit_year']]];
        }
        return $out;
    }

    private static function findingFraudLanguage(PDO $pdo): array
    {
        $pattern = 'misappropriat|embezzl|fraudulent|falsif|fictitious|forged|kickback|bribe|stolen|theft|personal use';
        $sql = "SELECT t.auditee_uei uei, t.report_id, COUNT(*) n, MIN(t.finding_ref_number) ref
                FROM fac_findings_text t
                JOIN entity e ON e.uei = t.auditee_uei AND t.audit_year = e.latest_audit_year
                JOIN fac_general g ON g.report_id = t.report_id AND g.is_active = 1
                WHERE t.finding_text REGEXP ?
                GROUP BY t.auditee_uei, t.report_id";
        $st = $pdo->prepare($sql);
        $st->execute([$pattern]);
        $out = [];
        foreach ($st as $r) {
            $out[] = ['uei' => $r['uei'], 'scope' => $r['report_id'], 'magnitude' => (float) $r['n'],
                'evidence' => ['matching_findings' => (int) $r['n'], 'example_finding' => $r['ref'], 'terms' => 'misappropriation/falsified/kickback/theft/personal use/etc']];
        }
        return $out;
    }

    private static function identifierLayering(PDO $pdo): array
    {
        $sql = "SELECT a.auditee_uei uei, COUNT(DISTINCT a.additional_uei) n
                FROM fac_additional_ueis a JOIN entity e ON e.uei = a.auditee_uei AND e.latest_audit_year IS NOT NULL
                WHERE a.auditee_uei IS NOT NULL
                GROUP BY a.auditee_uei HAVING n >= ?";
        $st = $pdo->prepare($sql);
        $st->execute([self::ADDL_UEI_MIN]);
        $out = [];
        foreach ($st as $r) {
            $out[] = ['uei' => $r['uei'], 'scope' => '', 'magnitude' => (float) $r['n'],
                'evidence' => ['additional_ueis' => (int) $r['n']]];
        }
        return $out;
    }

    private static function foreignFlow(PDO $pdo): array
    {
        $sql = "SELECT s.prime_entity_uei uei, COUNT(*) n, SUM(s.subaward_amount) amt,
                       GROUP_CONCAT(DISTINCT s.sub_vendor_country) countries, MAX(s.sub_vendor_name) ex
                FROM sam_assistance_subaward s JOIN entity e ON e.uei = s.prime_entity_uei
                WHERE s.prime_entity_uei IS NOT NULL AND s.sub_vendor_country IS NOT NULL
                  AND s.sub_vendor_country NOT IN ('USA','US','') AND s.status <> 'Deleted'
                GROUP BY s.prime_entity_uei";
        $out = [];
        foreach ($pdo->query($sql) as $r) {
            $out[] = ['uei' => $r['uei'], 'scope' => '', 'magnitude' => (float) ($r['amt'] ?? 0),
                'evidence' => ['foreign_countries' => $r['countries'], 'subawards' => (int) $r['n'],
                    'amount' => (float) ($r['amt'] ?? 0), 'example_sub' => $r['ex']]];
        }
        return $out;
    }

    private static function fundingShock(PDO $pdo): array
    {
        $tot = [];
        foreach ($pdo->query("SELECT auditee_uei uei, audit_year yr, total_amount_expended t
                              FROM fac_general WHERE is_active = 1 AND auditee_uei IS NOT NULL AND total_amount_expended IS NOT NULL") as $r) {
            $tot[$r['uei']][(int) $r['yr']] = (float) $r['t'];
        }
        $out = [];
        foreach ($tot as $uei => $byYear) {
            ksort($byYear);
            $years = array_keys($byYear);
            $best = null;
            for ($i = 1; $i < count($years); $i++) {
                $lo = $byYear[$years[$i - 1]]; $hi = $byYear[$years[$i]];
                if ($lo > 0 && $hi >= self::SHOCK_MIN_HI && $hi / $lo >= self::SHOCK_MULT) {
                    $ratio = $hi / $lo;
                    if ($best === null || $ratio > $best['ratio']) {
                        $best = ['from_year' => $years[$i - 1], 'to_year' => $years[$i], 'from' => $lo, 'to' => $hi, 'ratio' => round($ratio, 1)];
                    }
                }
            }
            if ($best) $out[] = ['uei' => $uei, 'scope' => '', 'magnitude' => $best['ratio'], 'evidence' => $best];
        }
        return $out;
    }

    private static function duplicateAmount(PDO $pdo): array
    {
        $sql = "SELECT auditee_uei uei, report_id, amount_expended amt, COUNT(*) n
                FROM fac_federal_awards
                WHERE auditee_uei IS NOT NULL AND amount_expended > 1000
                GROUP BY auditee_uei, report_id, amount_expended HAVING n >= ?";
        $st = $pdo->prepare($sql);
        $st->execute([self::DUP_MIN_REPEATS]);
        $out = [];
        foreach ($st as $r) {
            $out[] = ['uei' => $r['uei'], 'scope' => $r['report_id'] . '|' . $r['amt'], 'magnitude' => (float) $r['n'],
                'evidence' => ['amount' => (float) $r['amt'], 'repeats' => (int) $r['n'], 'report_id' => $r['report_id']]];
        }
        return $out;
    }

    private static function nameVolatility(PDO $pdo): array
    {
        $sql = "SELECT auditee_uei uei, COUNT(DISTINCT UPPER(REGEXP_REPLACE(auditee_name,'[^A-Za-z0-9]',''))) n,
                       SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT auditee_name SEPARATOR ' || '), ' || ', 3) names
                FROM fac_general
                WHERE is_active = 1 AND auditee_uei IS NOT NULL AND auditee_name IS NOT NULL
                GROUP BY auditee_uei HAVING n >= ?";
        $st = $pdo->prepare($sql);
        $st->execute([self::NAME_DISTINCT_MIN]);
        $out = [];
        foreach ($st as $r) {
            $out[] = ['uei' => $r['uei'], 'scope' => '', 'magnitude' => (float) $r['n'],
                'evidence' => ['distinct_names' => (int) $r['n'], 'names' => $r['names']]];
        }
        return $out;
    }

    private static function newUeiFastMoney(PDO $pdo): array
    {
        $sql = "SELECT e.uei, e.federal_latest, e.first_seen, s.registration_date reg
                FROM entity e JOIN sam_entity s ON s.uei = e.uei
                WHERE e.latest_audit_year IS NOT NULL AND s.registration_date IS NOT NULL
                  AND e.federal_latest >= ?
                  AND TIMESTAMPDIFF(MONTH, s.registration_date, COALESCE(e.first_seen, NOW())) BETWEEN 0 AND ?";
        $st = $pdo->prepare($sql);
        $st->execute([self::NEWUEI_MIN_FED, self::NEWUEI_MAX_MONTHS]);
        $out = [];
        foreach ($st as $r) {
            $out[] = ['uei' => $r['uei'], 'scope' => '', 'magnitude' => (float) ($r['federal_latest'] ?? 0),
                'evidence' => ['registration_date' => $r['reg'], 'first_seen' => $r['first_seen'], 'federal_latest' => (float) ($r['federal_latest'] ?? 0)]];
        }
        return $out;
    }

    // ======================================================================
    // helpers
    // ======================================================================

    /** per-UEI identity (latest active report) into the regular table _sig_entid (self-join needs non-TEMP). */
    private static function buildEntId(PDO $pdo): void
    {
        $pdo->exec("DROP TABLE IF EXISTS _sig_entid");
        $pdo->exec("CREATE TABLE _sig_entid (uei CHAR(12) PRIMARY KEY, certkey VARCHAR(255), addrkey VARCHAR(255))
                    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec(
            "INSERT INTO _sig_entid (uei, certkey, addrkey)
             SELECT uei, certkey, addrkey FROM (
               SELECT auditee_uei uei, UPPER(TRIM(auditee_certify_name)) certkey,
                      CONCAT(UPPER(REGEXP_REPLACE(auditee_address_line_1,'[^A-Za-z0-9]','')),'|',LEFT(auditee_zip,5)) addrkey,
                      ROW_NUMBER() OVER (PARTITION BY auditee_uei ORDER BY audit_year DESC) rn
               FROM fac_general WHERE is_active = 1 AND auditee_uei IS NOT NULL
             ) z WHERE rn = 1");
    }
}
