<?php
declare(strict_types=1);

/**
 * AERO — refresh the recipient directory denormalized onto `entity` (CLI).
 *
 * The `entity` hub is the always-fresh search/profile backbone, maintained INDEPENDENTLY
 * of the risk score (compute_scores.php). This recomputes the FAC-derived identity columns
 * (display_name / entity_type / state / audit_count / latest_audit_year / federal_latest /
 * is_hhs) from the current ACTIVE FAC reports, so Search and profiles never depend on the
 * score pipeline succeeding.
 *
 * Single atomic reap-and-fill: active auditees are filled from their latest active report;
 * UEIs with no active report have their identity NULLed, so a UEI dropped from FAC scope
 * (e.g. the prod HHS-prune) can't be surfaced by Search (which lists rows WHERE
 * latest_audit_year IS NOT NULL). Idempotent; cheap; safe to run after every FAC refresh.
 *
 * MUST run AFTER all fac_general mutations for a run — in particular AFTER the prod
 * HHS-prune (which deletes non-HHS reports), or pruned UEIs would keep stale identity.
 *
 * Usage:  php build_entity_directory.php
 */

$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
Env::load(dirname($root, 2) . '/.env');   // above web root (prod; first-set wins)
Env::load(dirname($root) . '/.env');      // repo root (local dev)

$pdo = Db::connect();
$t0 = microtime(true);

// One run at a time (the statement is a single UPDATE, but no point racing two).
if (!(int) $pdo->query("SELECT GET_LOCK('aero_entity_directory', 0)")->fetchColumn()) {
    fwrite(STDERR, "Another build_entity_directory.php run is already active; exiting.\n");
    exit(1);
}

// display_name falls back to the USAspending recipient name (usa_recipient.name) when an entity has
// no active FAC report. The funding-first usa_award rebuild adds FUNDED-BUT-UNAUDITED entities
// (never-filers) that by definition have no FAC row; the bare `SET display_name = a.nm` used to NULL
// them on every nightly, blanking their identity. Reap-by-NULL still applies to entities with NO
// source at all, and Search's contract is unchanged (it keys on latest_audit_year IS NOT NULL, which
// these still lack) — this only stops the directory from erasing a name we do have.
$affected = $pdo->exec(
    "UPDATE entity e
       LEFT JOIN (
         SELECT auditee_uei uei, auditee_name nm, entity_type et, auditee_state st,
                total_amount_expended fed, audit_year yr
         FROM (
           SELECT auditee_uei, auditee_name, entity_type, auditee_state, total_amount_expended, audit_year,
                  ROW_NUMBER() OVER (PARTITION BY auditee_uei
                                     ORDER BY audit_year DESC, fac_accepted_date DESC, report_id DESC) rn
           FROM fac_general WHERE auditee_uei IS NOT NULL AND is_active = 1
         ) t WHERE rn = 1
       ) a ON a.uei = e.uei
       LEFT JOIN (
         SELECT auditee_uei uei, COUNT(DISTINCT audit_year) ac
         FROM fac_general WHERE auditee_uei IS NOT NULL AND is_active = 1 GROUP BY auditee_uei
       ) c ON c.uei = e.uei
       LEFT JOIN (
         SELECT DISTINCT auditee_uei uei FROM fac_federal_awards
         WHERE federal_agency_prefix = '93' AND auditee_uei IS NOT NULL
       ) h ON h.uei = e.uei
       LEFT JOIN usa_recipient u ON u.uei = e.uei
       SET e.display_name      = COALESCE(a.nm, u.name),
           e.entity_type       = a.et,
           e.state             = COALESCE(a.st, e.state),
           e.audit_count       = c.ac,
           e.latest_audit_year = a.yr,
           e.federal_latest    = a.fed,
           e.is_hhs            = (h.uei IS NOT NULL)"
);

$dir = (int) $pdo->query("SELECT COUNT(*) FROM entity WHERE latest_audit_year IS NOT NULL")->fetchColumn();
printf("Entity directory refreshed: %d searchable recipients (%d rows touched) in %.1fs.\n",
    $dir, (int) $affected, microtime(true) - $t0);
