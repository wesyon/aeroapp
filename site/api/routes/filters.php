<?php
declare(strict_types=1);
/** GET /api/filters — available filter options for the dashboard. */

$years = $pdo->query(
    "SELECT DISTINCT audit_year FROM fac_general WHERE audit_year IS NOT NULL ORDER BY audit_year DESC"
)->fetchAll(PDO::FETCH_COLUMN);

$states = $pdo->query(
    "SELECT DISTINCT auditee_state FROM fac_general
     WHERE auditee_state IS NOT NULL AND auditee_state <> '' ORDER BY auditee_state"
)->fetchAll(PDO::FETCH_COLUMN);

// Agencies that actually appear on findings' awards (so the dropdown only offers
// agencies the agency filter can match), with names from the ALN catalog. Sourced
// from the finding↔award bridge (~222k rows), not fac_federal_awards (~2.6M) — the
// latter scan dominated this endpoint at ~1.9s.
$names = agency_names($pdo);
$prefixes = $pdo->query(
    "SELECT federal_agency_prefix p, COUNT(*) n FROM fac_finding_awards
     WHERE federal_agency_prefix IS NOT NULL AND federal_agency_prefix <> ''
     GROUP BY federal_agency_prefix ORDER BY n DESC"
)->fetchAll(PDO::FETCH_ASSOC);
$agencies = [];
foreach ($prefixes as $r) {
    $agencies[] = ['prefix' => $r['p'], 'name' => $names[$r['p']] ?? null];
}

json_out([
    'years'    => array_map('intval', $years),
    'states'   => $states,
    'agencies' => $agencies,
]);
