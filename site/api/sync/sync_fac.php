<?php
declare(strict_types=1);

/**
 * AERO — FAC sync (CLI).
 *
 * Pulls the 11 FAC dissemination endpoints into the local DB for a set of audit
 * years (default FY2022+). Strategy: clear the scope (deleting fac_general
 * cascades to every child), then insert in FK dependency order. The entity hub
 * is upserted from general rows. Type normalization (Y/N -> 1/0, dates, years,
 * amounts) happens here, not in the API.
 *
 * Usage:
 *   php sync_fac.php                      # full sync, years 2022-2024
 *   php sync_fac.php --years=2023,2024
 *   php sync_fac.php --years=2023 --reports=25   # small coherent test slice
 *   php sync_fac.php --report=2023-01-GSAFAC-0000000854
 *   php sync_fac.php --init               # apply schema.sql first
 */

$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
require $root . '/lib/Http.php';
require $root . '/lib/FacClient.php';
require $root . '/lib/CsvSource.php';
require $root . '/lib/Normalize.php';   // n_s/n_yn/n_yr/n_num/n_uei/n_d (unit-tested)

Env::load(dirname($root, 2) . '/.env');   // above web root (prod; first-set wins)
Env::load(dirname($root) . '/.env');      // repo root (local dev)

// ---- normalizers -----------------------------------------------------------
// The scalar value normalizers (n_s/n_yn/n_yr/n_num/n_uei/n_d) live in
// lib/Normalize.php so they can be unit-tested in isolation; map_row stays here
// because it depends on the per-table $SPEC tables defined below.

/** Map an API row to a DB row using a spec of [db_col => [api_field, type]]. */
function map_row(array $api, array $spec): array
{
    $out = [];
    foreach ($spec as $col => [$field, $type]) {
        $v = $api[$field] ?? null;
        $out[$col] = match ($type) {
            'yn'  => n_yn($v),
            'd'   => n_d($v),
            'yr'  => n_yr($v),
            'num' => n_num($v),
            'uei' => n_uei($v),
            'text' => ($v === null) ? null : (string) $v,   // untrimmed (split-fragment safe)
            default => n_s($v),
        };
    }
    return $out;
}

// ---- table specs (db_col => [api_field, type]) -----------------------------
$DENORM = [
    'auditee_uei'       => ['auditee_uei', 'uei'],
    'audit_year'        => ['audit_year', 'yr'],
    'fac_accepted_date' => ['fac_accepted_date', 'd'],
];

$SPEC_GENERAL = [
    'report_id' => ['report_id', 's'], 'audit_year' => ['audit_year', 'yr'],
    'auditee_uei' => ['auditee_uei', 'uei'], 'auditee_ein' => ['auditee_ein', 's'],
    'auditee_name' => ['auditee_name', 's'], 'auditee_address_line_1' => ['auditee_address_line_1', 's'],
    'auditee_city' => ['auditee_city', 's'], 'auditee_state' => ['auditee_state', 's'],
    'auditee_zip' => ['auditee_zip', 's'], 'auditee_email' => ['auditee_email', 's'],
    'auditee_phone' => ['auditee_phone', 's'], 'auditee_contact_name' => ['auditee_contact_name', 's'],
    'auditee_contact_title' => ['auditee_contact_title', 's'], 'auditee_certify_name' => ['auditee_certify_name', 's'],
    'auditee_certify_title' => ['auditee_certify_title', 's'], 'entity_type' => ['entity_type', 's'],
    'fy_start_date' => ['fy_start_date', 'd'], 'fy_end_date' => ['fy_end_date', 'd'],
    'audit_period_covered' => ['audit_period_covered', 's'], 'number_months' => ['number_months', 'num'],
    'audit_type' => ['audit_type', 's'], 'type_audit_code' => ['type_audit_code', 's'],
    'gaap_results' => ['gaap_results', 's'], 'data_source' => ['data_source', 's'],
    'auditor_firm_name' => ['auditor_firm_name', 's'], 'auditor_ein' => ['auditor_ein', 's'],
    'auditor_email' => ['auditor_email', 's'], 'auditor_phone' => ['auditor_phone', 's'],
    'auditor_contact_name' => ['auditor_contact_name', 's'], 'auditor_contact_title' => ['auditor_contact_title', 's'],
    'auditor_certify_name' => ['auditor_certify_name', 's'], 'auditor_certify_title' => ['auditor_certify_title', 's'],
    'auditor_address_line_1' => ['auditor_address_line_1', 's'], 'auditor_city' => ['auditor_city', 's'],
    'auditor_state' => ['auditor_state', 's'], 'auditor_zip' => ['auditor_zip', 's'],
    'auditor_country' => ['auditor_country', 's'], 'auditor_foreign_address' => ['auditor_foreign_address', 's'],
    'dollar_threshold' => ['dollar_threshold', 'num'], 'total_amount_expended' => ['total_amount_expended', 'num'],
    'is_low_risk_auditee' => ['is_low_risk_auditee', 'yn'], 'cognizant_agency' => ['cognizant_agency', 's'],
    'oversight_agency' => ['oversight_agency', 's'], 'agencies_with_prior_findings' => ['agencies_with_prior_findings', 's'],
    'is_going_concern_included' => ['is_going_concern_included', 'yn'],
    'is_internal_control_deficiency_disclosed' => ['is_internal_control_deficiency_disclosed', 'yn'],
    'is_internal_control_material_weakness_disclosed' => ['is_internal_control_material_weakness_disclosed', 'yn'],
    'is_material_noncompliance_disclosed' => ['is_material_noncompliance_disclosed', 'yn'],
    'is_aicpa_audit_guide_included' => ['is_aicpa_audit_guide_included', 'yn'],
    'is_additional_ueis' => ['is_additional_ueis', 'yn'], 'is_multiple_eins' => ['is_multiple_eins', 'yn'],
    'is_secondary_auditors' => ['is_secondary_auditors', 'yn'], 'is_public' => ['is_public', 'yn'],
    'is_sp_framework_required' => ['is_sp_framework_required', 'yn'], 'sp_framework_basis' => ['sp_framework_basis', 's'],
    'sp_framework_opinions' => ['sp_framework_opinions', 's'], 'fac_accepted_date' => ['fac_accepted_date', 'd'],
    'submitted_date' => ['submitted_date', 'd'], 'date_created' => ['date_created', 'd'],
    'ready_for_certification_date' => ['ready_for_certification_date', 'd'],
    'auditee_certified_date' => ['auditee_certified_date', 'd'], 'auditor_certified_date' => ['auditor_certified_date', 'd'],
    'resubmission_status' => ['resubmission_status', 's'], 'resubmission_version' => ['resubmission_version', 'num'],
];

$SPEC_AWARDS = [
    'report_id' => ['report_id', 's'], 'award_reference' => ['award_reference', 's'],
    'federal_agency_prefix' => ['federal_agency_prefix', 's'], 'federal_award_extension' => ['federal_award_extension', 's'],
    'federal_program_name' => ['federal_program_name', 's'], 'amount_expended' => ['amount_expended', 'num'],
    'federal_program_total' => ['federal_program_total', 'num'], 'cluster_name' => ['cluster_name', 's'],
    'other_cluster_name' => ['other_cluster_name', 's'], 'state_cluster_name' => ['state_cluster_name', 's'],
    'cluster_total' => ['cluster_total', 'num'], 'audit_report_type' => ['audit_report_type', 's'],
    'findings_count' => ['findings_count', 'num'], 'additional_award_identification' => ['additional_award_identification', 's'],
    'is_direct' => ['is_direct', 'yn'], 'is_loan' => ['is_loan', 'yn'], 'is_major' => ['is_major', 'yn'],
    'is_passthrough_award' => ['is_passthrough_award', 'yn'], 'loan_balance' => ['loan_balance', 'num'],
    'passthrough_amount' => ['passthrough_amount', 'num'],
] + $DENORM;

$SPEC_FINDINGS = [
    'report_id' => ['report_id', 's'], 'reference_number' => ['reference_number', 's'],
    'type_requirement' => ['type_requirement', 's'],
    'is_material_weakness' => ['is_material_weakness', 'yn'], 'is_significant_deficiency' => ['is_significant_deficiency', 'yn'],
    'is_modified_opinion' => ['is_modified_opinion', 'yn'], 'is_other_findings' => ['is_other_findings', 'yn'],
    'is_other_matters' => ['is_other_matters', 'yn'], 'is_questioned_costs' => ['is_questioned_costs', 'yn'],
    'is_repeat_finding' => ['is_repeat_finding', 'yn'], 'prior_finding_ref_numbers' => ['prior_finding_ref_numbers', 's'],
] + $DENORM;

$SPEC_FTEXT = [
    'report_id' => ['report_id', 's'], 'finding_ref_number' => ['finding_ref_number', 's'],
    'finding_text' => ['finding_text', 'text'], 'contains_chart_or_table' => ['contains_chart_or_table', 'yn'],
] + $DENORM;

$SPEC_CAP = [
    'report_id' => ['report_id', 's'], 'finding_ref_number' => ['finding_ref_number', 's'],
    'planned_action' => ['planned_action', 'text'], 'contains_chart_or_table' => ['contains_chart_or_table', 'yn'],
] + $DENORM;

$SPEC_PASS = [
    'report_id' => ['report_id', 's'], 'award_reference' => ['award_reference', 's'],
    'passthrough_id' => ['passthrough_id', 's'], 'passthrough_name' => ['passthrough_name', 's'],
] + $DENORM;

$SPEC_NOTES = [
    'report_id' => ['report_id', 's'], 'title' => ['title', 's'], 'content' => ['content', 's'],
    'accounting_policies' => ['accounting_policies', 's'], 'is_minimis_rate_used' => ['is_minimis_rate_used', 'yn'],
    'rate_explained' => ['rate_explained', 's'], 'contains_chart_or_table' => ['contains_chart_or_table', 'yn'],
] + $DENORM;

$SPEC_ADDUEIS = ['report_id' => ['report_id', 's'], 'additional_uei' => ['additional_uei', 'uei']] + $DENORM;
$SPEC_ADDEINS = ['report_id' => ['report_id', 's'], 'additional_ein' => ['additional_ein', 's']] + $DENORM;

$SPEC_SECAUD = [
    'report_id' => ['report_id', 's'], 'auditor_name' => ['auditor_name', 's'], 'auditor_ein' => ['auditor_ein', 's'],
    'address_street' => ['address_street', 's'], 'address_city' => ['address_city', 's'],
    'address_state' => ['address_state', 's'], 'address_zipcode' => ['address_zipcode', 's'],
    'contact_name' => ['contact_name', 's'], 'contact_title' => ['contact_title', 's'],
    'contact_email' => ['contact_email', 's'], 'contact_phone' => ['contact_phone', 's'],
] + $DENORM;

$SPEC_RESUB = [
    'report_id' => ['report_id', 's'], 'version' => ['version', 'num'], 'status' => ['status', 's'],
    'previous_report_id' => ['previous_report_id', 's'], 'next_report_id' => ['next_report_id', 's'],
    'original_submission_date' => ['original_submission_date', 'd'],
] + $DENORM;

// table => [endpoint, db_table, spec, isGeneral]
$TABLES = [
    ['general', 'fac_general', $SPEC_GENERAL, true],
    ['federal_awards', 'fac_federal_awards', $SPEC_AWARDS, false],
    ['findings', 'fac_findings', $SPEC_FINDINGS, false],
    ['findings_text', 'fac_findings_text', $SPEC_FTEXT, false],
    ['corrective_action_plans', 'fac_corrective_action_plans', $SPEC_CAP, false],
    ['passthrough', 'fac_passthrough', $SPEC_PASS, false],
    ['notes_to_sefa', 'fac_notes_to_sefa', $SPEC_NOTES, false],
    ['additional_ueis', 'fac_additional_ueis', $SPEC_ADDUEIS, false],
    ['additional_eins', 'fac_additional_eins', $SPEC_ADDEINS, false],
    ['secondary_auditors', 'fac_secondary_auditors', $SPEC_SECAUD, false],
    ['resubmission', 'fac_resubmission', $SPEC_RESUB, false],
];

// On the quota-bound prod DB these tables are kept OFF (read only by the local-disabled
// admin console; the live API never touches them) so the 3 GB cap holds. They remain
// full locally. Mirrors deploy.ps1's $skip list — without this, the nightly would
// re-INSERT and rebuild ~600 MB of admin-only data, re-tripping the quota lockdown.
if (strtolower((string) Env::get('APP_ENV', '')) === 'prod') {
    $PROD_SKIP = ['fac_passthrough', 'fac_notes_to_sefa'];
    $TABLES = array_values(array_filter($TABLES, fn ($t) => !in_array($t[1], $PROD_SKIP, true)));
}

// ---- args ------------------------------------------------------------------
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/', $a, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}
// Default scope: FY2022 (first full year of universal UEI adoption) through the
// current calendar year — i.e. "2022 to present". Auto-extends each year, so the
// nightly --since refresh keeps picking up the newest audit_year. Override with --years=.
$years    = isset($args['years'])
    ? array_map('trim', explode(',', (string) $args['years']))
    : array_map('strval', range(2022, (int) date('Y')));
$reportsN = isset($args['reports']) ? (int) $args['reports'] : null;
$oneReport = $args['report'] ?? null;
// --since=YYYY-MM-DD : incremental refresh of reports accepted on/after the date
$sinceMode = (isset($args['since']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $args['since']))
    ? (string) $args['since'] : null;

$pdo = Db::connect();

// One run at a time: this sync DELETES its scope before reloading, so two
// concurrent runs (console + CLI) could interleave deletes and inserts.
// The lock auto-releases when the process exits.
if (!(int) $pdo->query("SELECT GET_LOCK('aero_sync_fac', 0)")->fetchColumn()) {
    fwrite(STDERR, "Another sync_fac.php run is already active; exiting.\n");
    exit(1);
}

if (isset($args['init'])) {
    echo "Applying schema.sql...\n";
    $pdo->exec(file_get_contents($root . '/schema.sql'));
    echo "Schema applied.\n";
}

$up     = new Upserter($pdo);
$source = $args['source'] ?? 'api';

// ---- determine scope + row source ------------------------------------------
$ids   = null;
$fetch = null;

if ($source === 'csv') {
    $csvDir = (string) ($args['csvdir'] ?? Env::get('FAC_CSV_DIR') ?? (dirname($root) . '/csv/fac'));
    $csv = new CsvSource($csvDir, $years);
    echo "Seeding from FAC CSV extracts: $csvDir  (years " . implode(',', $years) . ")\n";
    $fetch = fn (string $ep, callable $cb) => $csv->fetch($ep, $cb);
} else {
    $fac = new FacClient(Env::require('FAC_BASE_URL'), Env::require('FAC_API_KEY'));
    if ($sinceMode !== null) {
        // reports are immutable once accepted (a resubmission gets a NEW report_id),
        // so filtering by fac_accepted_date is a correct incremental delta.
        $filter = 'fac_accepted_date=gte.' . rawurlencode($sinceMode)
                . '&audit_year=in.(' . implode(',', $years) . ')';
        // The resubmission view chokes on the COMBINED date+year predicate (measured:
        // each filter alone < 3s at limit=500; combined times out past 130s — a
        // FAC-side planner pathology). Date-only is correct there: the year filter is
        // only scope-trimming, and out-of-scope rows fail their FK and are skipped.
        $resubFilter = 'fac_accepted_date=gte.' . rawurlencode($sinceMode);
        echo "Incremental sync: reports accepted since $sinceMode (years " . implode(',', $years) . ")\n";
    } else {
        if ($oneReport) {
            $ids = [(string) $oneReport];
        } elseif ($reportsN) {
            $ids = $fac->reportIds($years, $reportsN);
            echo 'Report-scoped sync: ' . count($ids) . " reports from years " . implode(',', $years) . "\n";
        }
        $quote  = fn (string $v): string => '"' . str_replace('"', '', $v) . '"';
        $filter = $ids !== null
            ? 'report_id=in.(' . implode(',', array_map($quote, $ids)) . ')'
            : 'audit_year=in.(' . implode(',', $years) . ')';
    }
    $fetch  = fn (string $ep, callable $cb) =>
        $fac->each($ep, ($ep === 'resubmission' && isset($resubFilter)) ? $resubFilter : $filter, $cb);
}

// optional: restrict to specific endpoints (per-table reload, no general cascade)
$onlyTables = isset($args['only']) ? array_map('trim', explode(',', (string) $args['only'])) : null;
if ($onlyTables !== null) {
    // Deleting fac_findings cascades to its FK children (findings_text,
    // corrective_action_plans, and fac_finding_extract), so a findings-only
    // reload would silently wipe the narratives: pull the text tables into the
    // run. The extract can only be rebuilt by parse_findings.php — remind.
    if (in_array('findings', $onlyTables, true)) {
        foreach (['findings_text', 'corrective_action_plans'] as $dep) {
            if (!in_array($dep, $onlyTables, true)) $onlyTables[] = $dep;
        }
        fwrite(STDERR, "note: --only=findings cascades to findings_text / corrective_action_plans;"
            . " reloading those too.\n      fac_finding_extract is wiped by the same cascade —"
            . " run api/sync/parse_findings.php after this sync.\n");
    }
    $TABLES = array_values(array_filter($TABLES, fn ($t) => in_array($t[0], $onlyTables, true)));
}

// Narratives (finding_text, planned_action) are split across multiple rows in
// BOTH the CSV extract and the API (verified identical chunking), keyed on
// (report_id, finding_ref_number) with no sequence column. We concatenate
// fragments in ARRIVAL ORDER rather than overwrite; the text columns are mapped
// untrimmed ('text' type) so boundary whitespace between fragments is preserved.
// Correctness therefore depends on the source emitting a finding's chunks in
// narrative order — deterministic for the CSV seed (file order); for the API
// path see the ordering caveat in FacClient::each().
function make_concat_upsert(PDO $pdo, string $table, string $textCol): callable
{
    $stmt = null;
    return function (array $row) use ($pdo, $table, $textCol, &$stmt): void {
        if ($stmt === null) {
            $cols = array_keys($row);
            $ph   = implode(',', array_map(fn ($c) => ":$c", $cols));
            // FAC splits long narratives across rows at word boundaries, often
            // dropping the separator. Re-join, inserting a single space only when
            // neither side already carries boundary whitespace (avoids double spaces).
            $stmt = $pdo->prepare(
                "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES ($ph) "
              . "ON DUPLICATE KEY UPDATE `$textCol` = CONCAT("
              .   "COALESCE(`$textCol`,''), "
              .   "CASE WHEN `$textCol` IS NULL OR `$textCol` = '' "
              .        "OR `$textCol` REGEXP '[[:space:]]\$' "
              .        "OR VALUES(`$textCol`) REGEXP '^[[:space:]]' THEN '' ELSE ' ' END, "
              .   "COALESCE(VALUES(`$textCol`),''))"
            );
        }
        $stmt->execute($row);
    };
}
$ftUpsert  = make_concat_upsert($pdo, 'fac_findings_text', 'finding_text');
$capUpsert = make_concat_upsert($pdo, 'fac_corrective_action_plans', 'planned_action');

// ---- scope predicate (sanitized literal; reused for clear / backup / restore) ----
if ($sinceMode !== null) {
    $scopeSql = 'fac_accepted_date >= ' . $pdo->quote($sinceMode)
              . ' AND audit_year IN (' . implode(',', array_map('intval', $years)) . ')';
} elseif ($ids !== null) {
    $scopeSql = 'report_id IN (' . implode(',', array_map([$pdo, 'quote'], $ids)) . ')';
} else {
    $scopeSql = 'audit_year IN (' . implode(',', array_map('intval', $years)) . ')';
}

// --safe: snapshot the in-scope rows first so a failed pull rolls back. The scope
// DELETE commits before the re-insert, so without this a mid-run failure (network,
// API) would leave the slice missing. Skipped for --only; not for a full resync.
$safe = isset($args['safe']) && $onlyTables === null;
$bakTables = array_column($TABLES, 1);
$dropBaks = function () use (&$pdo, $bakTables) {   // &$pdo: survives a reconnect
    foreach ($bakTables as $t) $pdo->exec("DROP TABLE IF EXISTS `_bak_$t`");
};
if ($safe) {
    echo "Snapshotting in-scope rows (--safe)...\n";
    foreach ($bakTables as $t) {
        $pdo->exec("DROP TABLE IF EXISTS `_bak_$t`");
        $pdo->exec("CREATE TABLE `_bak_$t` AS SELECT * FROM `$t` WHERE $scopeSql");
    }
}

try {
    // ---- clear scope -------------------------------------------------------
    echo "Clearing existing rows in scope...\n";
    if ($onlyTables !== null) {
        foreach ($TABLES as [$ep, $dbT]) $pdo->exec("DELETE FROM `$dbT` WHERE $scopeSql");  // per-table, no cascade
    } else {
        $pdo->exec("DELETE FROM fac_general WHERE $scopeSql");  // cascades to children
    }

// ---- sync each table in FK dependency order --------------------------------
function log_sync(PDO $pdo, string $scope, string $table, int $rows, int $errs, string $start, ?string $msg = null): void
{
    $pdo->prepare(
        "INSERT INTO sync_log (source, scope, table_name, rows_upserted, status, message, started_at, finished_at)
         VALUES ('fac', :scope, :t, :rows, :status, :msg, :start, UTC_TIMESTAMP())"
    )->execute([
        ':scope' => $scope, ':t' => $table, ':rows' => $rows,
        ':status' => ($msg !== null || $errs) ? 'partial' : 'ok',
        ':msg' => $msg ?? ($errs ? "$errs rows skipped (FK/parse)" : null),
        ':start' => $start,
    ]);
}

/**
 * Plain-language reason a row failed to insert, derived from the first error — so the Data
 * Status "Incomplete sync runs" panel says WHY, not just "(FK/parse)". The common, benign
 * case is a foreign-key reference to a parent outside the synced scope (e.g. resubmission
 * lineage pointing at pre-FY2022 reports we don't hold).
 */
function skip_explain(string $table, ?string $err): string
{
    if ($err === null) return 'insert error (cause not captured)';
    if (preg_match('/\b1452\b|foreign key/i', $err)) {
        if ($table === 'fac_resubmission') {
            return 'resubmission lineage referencing reports outside our FY2022+ window (foreign key) — expected, harmless';
        }
        return 'referenced a parent record outside the synced scope (foreign key)';
    }
    if (preg_match('/\b1062\b|duplicate/i', $err))                              return 'duplicate key';
    if (preg_match('/\b1264\b|\b1366\b|out of range|incorrect .* value|truncated/i', $err)) return 'a value out of range or wrong type';
    return 'insert error: ' . substr(preg_replace('/\s+/', ' ', $err), 0, 80);
}

$scopeLabel = $ids !== null ? count($ids) . ' reports' : 'years ' . implode(',', $years);

foreach ($TABLES as [$endpoint, $dbTable, $spec, $isGeneral]) {
    $start = gmdate('Y-m-d H:i:s');   // UTC, matching log_sync's UTC_TIMESTAMP() finish
    $rows = 0;
    $errs = 0;
    $firstErr = null;
    // resubmission is BEST-EFFORT: it's auxiliary lineage metadata nothing in
    // scoring/UI reads, has no CSV fallback, and FAC's endpoint intermittently
    // times out on date filters — a failure there must not abort (and, under
    // --safe, roll back) an otherwise-good nightly run.
    $optional = ($endpoint === 'resubmission');

    try {
        $fetch($endpoint, function (array $page) use (&$rows, &$errs, &$firstErr, $pdo, $up, $ftUpsert, $capUpsert, $spec, $dbTable, $isGeneral) {
        $pdo->beginTransaction();
        foreach ($page as $api) {
            try {
                if ($isGeneral) {
                    $uei = n_uei($api['auditee_uei'] ?? null);
                    if ($uei !== null) {
                        $up->insert('entity', [
                            'uei'        => $uei,
                            'legal_name' => n_s($api['auditee_name'] ?? null),
                            'ein'        => n_s($api['auditee_ein'] ?? null),
                            'state'      => n_s($api['auditee_state'] ?? null),
                            'has_fac'    => 1,
                        ]);
                    }
                }
                $row = map_row($api, $spec);
                if ($dbTable === 'fac_federal_awards') {
                    $row['aln'] = ($row['federal_agency_prefix'] && $row['federal_award_extension'])
                        ? $row['federal_agency_prefix'] . '.' . $row['federal_award_extension']
                        : null;
                }
                if ($dbTable === 'fac_findings_text') {
                    $ftUpsert($row);   // concatenate split narrative fragments
                } elseif ($dbTable === 'fac_corrective_action_plans') {
                    $capUpsert($row);  // same: CAP text is split across rows too
                } else {
                    $up->insert($dbTable, $row);
                }
                // An additional UEI is covered by this audit but isn't a primary auditee —
                // put it in the hub flagged has_addl so SAM enrichment resolves its name +
                // status (FAC gives us only the bare UEI). Upsert touches has_addl only, so
                // a UEI that is ALSO a primary auditee keeps its has_fac / legal_name.
                if ($dbTable === 'fac_additional_ueis' && ($au = n_uei($api['additional_uei'] ?? null)) !== null) {
                    $up->insert('entity', ['uei' => $au, 'has_addl' => 1]);
                }
                // The findings endpoint is grained per (finding, award): dedupe the
                // finding into fac_findings (above) and record each award link here.
                if ($dbTable === 'fac_findings') {
                    $ar = n_s($api['award_reference'] ?? null);
                    if ($ar !== null) {
                        $up->insert('fac_finding_awards', [
                            'report_id'         => $row['report_id'],
                            'reference_number'  => $row['reference_number'],
                            'award_reference'   => $ar,
                            'auditee_uei'       => $row['auditee_uei'],
                            'audit_year'        => $row['audit_year'],
                            'fac_accepted_date' => $row['fac_accepted_date'],
                        ]);
                    }
                }
                $rows++;
            } catch (Throwable $e) {
                $errs++;
                $firstErr ??= $e->getMessage();
            }
        }
        $pdo->commit();
        });
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();   // mid-page failure
        if (!$optional) throw $e;
        // a minutes-long endpoint stall can have killed the DB connection (observed
        // on shared hosting); reconnect so logging the skip doesn't fail the run
        try { $pdo->query('SELECT 1'); } catch (Throwable $dead) { $pdo = Db::connect(); }
        log_sync($pdo, $scopeLabel, $dbTable, $rows, $errs, $start,
                 'endpoint failed, skipped this run: ' . substr($e->getMessage(), 0, 200));
        fwrite(STDERR, "  $endpoint FAILED (optional endpoint, continuing): " . substr($e->getMessage(), 0, 120) . "\n");
        continue;
    }

    log_sync($pdo, $scopeLabel, $dbTable, $rows, $errs, $start,
             $errs ? "$errs of " . ($rows + $errs) . " rows skipped — " . skip_explain($dbTable, $firstErr) : null);
    $note = $errs ? "  ($errs skipped: " . substr((string) $firstErr, 0, 80) . ')' : '';
    printf("  %-28s %6d rows%s\n", $endpoint, $rows, $note);
}

// Denormalize the federal agency prefix onto the bridge so the dashboard's
// by-agency aggregation is a single-table scan (idempotent: only fills NULLs).
$pdo->exec(
    "UPDATE fac_finding_awards fb
        JOIN fac_federal_awards fa ON fa.report_id = fb.report_id AND fa.award_reference = fb.award_reference
         SET fb.federal_agency_prefix = fa.federal_agency_prefix
       WHERE fb.federal_agency_prefix IS NULL"
);

// Reconcile award-reference padding drift: FAC's findings and awards endpoints
// occasionally pad the same report's award reference to different widths
// (AWARD-00001 vs AWARD-0001), orphaning the soft-linked bridge row so its
// findings vanish from by-agency rollups. Find the orphans cheaply (PK anti-join
// into a temp table) then re-point only those to the award with the matching
// NUMERIC value, guarded to a unique match (a report can hold two awards sharing
// a number under different padding — leave those alone). Whole-bridge numeric
// matching took 17 min; orphan-first is sub-second. Mirrors migration
// 2026-06-11_repair_finding_award_padding.sql.
$pdo->exec("DROP TEMPORARY TABLE IF EXISTS _aero_award_orphans");
$pdo->exec(
    "CREATE TEMPORARY TABLE _aero_award_orphans AS
       SELECT fb.report_id, fb.reference_number, fb.award_reference
       FROM fac_finding_awards fb
       LEFT JOIN fac_federal_awards fa
         ON fa.report_id = fb.report_id AND fa.award_reference = fb.award_reference
       WHERE fa.report_id IS NULL AND fb.award_reference REGEXP '^AWARD-[0-9]+$'"
);
$pdo->exec(
    "UPDATE fac_finding_awards fb
       JOIN _aero_award_orphans o
         ON o.report_id = fb.report_id AND o.reference_number = fb.reference_number
        AND o.award_reference = fb.award_reference
       JOIN fac_federal_awards fa
         ON fa.report_id = o.report_id AND fa.award_reference REGEXP '^AWARD-[0-9]+$'
        AND CAST(SUBSTRING(fa.award_reference, 7) AS UNSIGNED) = CAST(SUBSTRING(o.award_reference, 7) AS UNSIGNED)
        SET fb.award_reference = fa.award_reference, fb.federal_agency_prefix = fa.federal_agency_prefix
      WHERE (SELECT COUNT(*) FROM fac_federal_awards fc
             WHERE fc.report_id = o.report_id AND fc.award_reference REGEXP '^AWARD-[0-9]+$'
               AND CAST(SUBSTRING(fc.award_reference, 7) AS UNSIGNED)
                 = CAST(SUBSTRING(o.award_reference, 7) AS UNSIGNED)) = 1"
);
$pdo->exec("DROP TEMPORARY TABLE IF EXISTS _aero_award_orphans");

// Provenance: first_seen = when the recipient first appeared in our FAC universe
// (its earliest accepted report). Fill-NULLs-only, so the stamp never moves once set;
// entities inserted earlier in this run get stamped here.
$pdo->exec(
    "UPDATE entity e
       JOIN (SELECT auditee_uei u, MIN(fac_accepted_date) d FROM fac_general
              WHERE auditee_uei IS NOT NULL AND fac_accepted_date IS NOT NULL
              GROUP BY auditee_uei) g ON g.u = e.uei
        SET e.first_seen = g.d
      WHERE e.first_seen IS NULL"
);

// Mark the active report per (uei, audit_year): a resubmission gets a NEW report_id
// and the superseded one stays, so aggregates filter on is_active = 1 to avoid
// double-counting. Recomputed over the whole table every run (cheap; idempotent).
$pdo->exec(
    "UPDATE fac_general g
        JOIN (SELECT report_id,
                     ROW_NUMBER() OVER (PARTITION BY auditee_uei, audit_year
                                        ORDER BY fac_accepted_date DESC, report_id DESC) rn
                FROM fac_general WHERE auditee_uei IS NOT NULL) t ON t.report_id = g.report_id
         SET g.is_active = (t.rn = 1)
       WHERE g.is_active <> (t.rn = 1)"
);
} catch (Throwable $e) {
    if ($safe) {
        fwrite(STDERR, 'Sync failed: ' . $e->getMessage() . "\n  rolling back to snapshot...\n");
        // the failure itself may have been a dead DB connection — the rollback needs
        // a live one, or the restore dies on its first statement (observed on prod)
        try { $pdo->query('SELECT 1'); } catch (Throwable $dead) { $pdo = Db::connect(); }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($bakTables as $t) {
            $pdo->exec("DELETE FROM `$t` WHERE $scopeSql");
            $pdo->exec("INSERT INTO `$t` SELECT * FROM `_bak_$t`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        $dropBaks();
        fwrite(STDERR, "Rolled back; data restored to pre-sync state.\n");
    }
    throw $e;
}
if ($safe) $dropBaks();

echo "Done.\n";
