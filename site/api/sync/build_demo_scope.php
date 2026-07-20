<?php
declare(strict_types=1);

/**
 * build_demo_scope.php — DEMO-SCOPE prune for the quota-bound prod prototype.
 *
 * Prod is a capability DEMO (client review before the gov-environment migration), not the
 * system of record. It sits under Hostinger's 3 GB per-database cap. This prune keeps prod
 * showing every FEATURE and every salient case while shedding the clean, unremarkable
 * background that only exists to be comprehensive — which is the gov environment's job.
 *
 * RULE (entity-level salience): an auditee is KEPT whole (all years) if it is salient —
 *   - has >=1 finding in any year, OR
 *   - appears in delinquency_preview (a flagged non-filer / delinquent), OR
 *   - is a crosswalk-family member (entity_related_uei / fac_additional_ueis), OR
 *   - is a succession sibling (state_uei) of any kept entity.
 * Everything else that HAS an audit and is clean every year is DROPPED across all its source
 * tables. subaward_edge is kept WHOLE (the money-flow network is denormalised and self-contained,
 * so a kept entity's passthrough view survives even when a counterparty is dropped).
 *
 * Every dropped entity is recorded in scope_manifest (uei, name, reason, human message) BEFORE
 * deletion, so a 0-result search on prod can explain the absence ("real auditee, excluded from
 * this demo because it filed clean audits with no findings; available in production").
 *
 * SAFE BY DEFAULT: dry-run unless --apply is passed. Dry-run computes keep/drop, (re)builds
 * scope_manifest, and reports exactly what WOULD be deleted per table — it deletes nothing.
 *
 *   php api/sync/build_demo_scope.php            # dry-run: report only
 *   php api/sync/build_demo_scope.php --apply    # perform the prune
 */

$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
Env::load(dirname($root, 2) . '/.env');
Env::load(dirname($root) . '/.env');

$args  = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$apply = isset($args['apply']);
$pdo   = Db::connect();
$t0    = microtime(true);

function out(string $s): void { echo $s . "\n"; }
out($apply ? '=== build_demo_scope: APPLY (will delete) ===' : '=== build_demo_scope: DRY-RUN (no deletes) ===');

// ---------------------------------------------------------------------------------------
// 1) KEEP-SET — salient / protected entities. Regular (non-temp) tables so we can reference
//    them repeatedly in one statement; dropped at the end.
// ---------------------------------------------------------------------------------------
// Manual research/protection allowlist — entities that must ALWAYS stay on demo prod (e.g. client
// research targets), even if clean/non-HHS. Self-heal so the prune never errors on a fresh DB.
$pdo->exec("CREATE TABLE IF NOT EXISTS scope_keep (
  uei CHAR(12) NOT NULL PRIMARY KEY, note VARCHAR(255) NULL,
  added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec('DROP TABLE IF EXISTS _keep');
$pdo->exec('CREATE TABLE _keep (uei CHAR(12) PRIMARY KEY)');
$pdo->exec("INSERT IGNORE INTO _keep (uei)
      SELECT DISTINCT auditee_uei FROM fac_findings        WHERE auditee_uei   IS NOT NULL
      UNION SELECT uei             FROM delinquency_preview
      UNION SELECT related_uei     FROM entity_related_uei
      UNION SELECT uei             FROM entity_related_uei
      UNION SELECT additional_uei  FROM fac_additional_ueis WHERE additional_uei IS NOT NULL AND additional_uei <> ''
      UNION SELECT uei             FROM scope_keep");   // manual research/protection allowlist

// 1b) Succession protection: pull in state_uei group siblings of any kept member (an entity can be
//     clean under its current UEI but have a finding under a predecessor). state_uei.ueis is a
//     newline-delimited blob, so resolve it in PHP.
$keep = [];
foreach ($pdo->query('SELECT uei FROM _keep')->fetchAll(PDO::FETCH_COLUMN) as $u) $keep[$u] = true;
$addSibling = $pdo->prepare('INSERT IGNORE INTO _keep (uei) VALUES (?)');
$sibAdded = 0;
foreach ($pdo->query('SELECT ueis FROM state_uei')->fetchAll(PDO::FETCH_COLUMN) as $blob) {
    $members = array_values(array_filter(array_map('trim', preg_split('/\R+/', (string) $blob) ?: [])));
    if (!$members) continue;
    $hit = false;
    foreach ($members as $m) { if (isset($keep[$m])) { $hit = true; break; } }
    if (!$hit) continue;
    foreach ($members as $m) {
        if ($m !== '' && !isset($keep[$m])) { $addSibling->execute([$m]); $keep[$m] = true; $sibAdded++; }
    }
}
$keepCount = (int) $pdo->query('SELECT COUNT(*) FROM _keep')->fetchColumn();
out(sprintf('keep-set: %d salient/protected UEIs (+%d succession siblings)', $keepCount, $sibAdded));

// ---------------------------------------------------------------------------------------
// 2) DROP-SET — auditees that HAVE an audit but are not in the keep-set (clean every year,
//    unprotected). Entities with no audit at all are award/registration-only and stay
//    searchable (they are not "clean audits").
// ---------------------------------------------------------------------------------------
$pdo->exec('DROP TABLE IF EXISTS _drop');
$pdo->exec('CREATE TABLE _drop (uei CHAR(12) PRIMARY KEY)');
$pdo->exec('INSERT IGNORE INTO _drop (uei)
      SELECT DISTINCT g.auditee_uei FROM fac_general g
      LEFT JOIN _keep k ON k.uei = g.auditee_uei
      WHERE g.auditee_uei IS NOT NULL AND k.uei IS NULL');
$dropCount = (int) $pdo->query('SELECT COUNT(*) FROM _drop')->fetchColumn();
out("drop-set: $dropCount clean, unprotected auditees");

// Reports belonging to dropped auditees (for the report-scoped fac_* anti-joins).
$pdo->exec('DROP TABLE IF EXISTS _drop_reports');
$pdo->exec('CREATE TABLE _drop_reports (report_id VARCHAR(40) PRIMARY KEY)');
$pdo->exec('INSERT IGNORE INTO _drop_reports (report_id)
      SELECT DISTINCT g.report_id FROM fac_general g JOIN _drop d ON d.uei = g.auditee_uei');

// ---------------------------------------------------------------------------------------
// 3) scope_manifest — record every dropped entity BEFORE deletion so search can explain it.
// ---------------------------------------------------------------------------------------
$pdo->exec("CREATE TABLE IF NOT EXISTS scope_manifest (
  uei CHAR(12) NOT NULL PRIMARY KEY, name VARCHAR(255) NULL, state CHAR(2) NULL,
  entity_type VARCHAR(32) NULL, latest_audit_year SMALLINT NULL,
  reason VARCHAR(24) NOT NULL, detail VARCHAR(255) NULL, KEY idx_sm_reason (reason)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
// ACCUMULATE — never rebuild the manifest from the live drop-set. An entity, once dropped, VANISHES
// from the source tables (fac_general etc.), so it can't be re-derived here; a DELETE-then-rebuild
// would silently erase every previously-recorded entity (learned the hard way — a dry-run rebuilt it
// from the 6 leftover stragglers). INSERT IGNORE only ADDS newly-dropped entities to the running list.
$pdo->exec("INSERT IGNORE INTO scope_manifest (uei, name, state, entity_type, latest_audit_year, reason, detail)
      SELECT e.uei, COALESCE(NULLIF(e.display_name,''), e.legal_name), e.state, e.entity_type, e.latest_audit_year,
             'clean_audit',
             CONCAT('Filed clean audits',
                    CASE WHEN e.latest_audit_year IS NOT NULL THEN CONCAT(' through FY', e.latest_audit_year) ELSE '' END,
                    ' with no findings — excluded from this demo''s findings-focused scope. Full detail is available in production.')
      FROM _drop d JOIN entity e ON e.uei = d.uei");
// SELF-CORRECT — an entity that became salient again (re-filed with findings, joined a crosswalk
// family) is back in scope, so drop its stale manifest row and let Search surface it normally.
$pdo->exec("DELETE FROM scope_manifest WHERE reason='clean_audit' AND uei IN (SELECT uei FROM _keep)");
$manifestCount = (int) $pdo->query("SELECT COUNT(*) FROM scope_manifest WHERE reason='clean_audit'")->fetchColumn();
out("scope_manifest: $manifestCount clean_audit rows total (accumulated)");

// ---------------------------------------------------------------------------------------
// 4) Per-table impact report (always) + deletes (only with --apply).
// KEEP WHOLE (never pruned): subaward_edge, sam_acquisition_subaward, delinquency_preview,
// entity_related_uei, scope_manifest.
// ---------------------------------------------------------------------------------------
// report-scoped fac_* tables (anti-join on _drop_reports)
$byReport = ['fac_additional_eins','fac_additional_ueis','fac_corrective_action_plans','fac_finding_awards',
             'fac_finding_extract','fac_findings_text','fac_findings','fac_resubmission','fac_secondary_auditors',
             'fac_federal_awards','fac_general'];
// uei-scoped tables (join on _drop.uei)
$byUei = ['aero_score','entity_map_point','repeat_preview','sam_business_type','sam_entity','sam_entity_naics',
          'subaward_entity_type','usa_recipient','usa_recipient_business_type','entity'];

$countByReport = function (string $tbl) use ($pdo): int {
    $stmt = $pdo->query("SELECT COUNT(*) FROM `$tbl` t JOIN _drop_reports r ON r.report_id = t.report_id");
    return (int) $stmt->fetchColumn();
};
$countByUei = function (string $tbl, string $col) use ($pdo): int {
    $stmt = $pdo->query("SELECT COUNT(*) FROM `$tbl` t JOIN _drop d ON d.uei = t.`$col`");
    return (int) $stmt->fetchColumn();
};

out("\n-- rows that " . ($apply ? 'were' : 'WOULD be') . " deleted --");
$total = 0;
foreach ($byReport as $t) { $n = $countByReport($t); $total += $n; if ($n) out(sprintf('  %-30s %d', $t, $n)); }
// usa_award by recipient_uei, and its txn months by award join
$nAward = $countByUei('usa_award', 'recipient_uei'); $total += $nAward; if ($nAward) out(sprintf('  %-30s %d', 'usa_award', $nAward));
$nTxn = (int) $pdo->query('SELECT COUNT(*) FROM usa_award_txn_month t JOIN usa_award a ON a.award_id=t.award_id JOIN _drop d ON d.uei=a.recipient_uei')->fetchColumn();
$total += $nTxn; if ($nTxn) out(sprintf('  %-30s %d', 'usa_award_txn_month', $nTxn));
// usa_award_outlay_month is the twin of txn_month and MUST be pruned the same way, or every
// dropped entity leaves its monthly outlay rows behind as orphans (483 MB table — it went
// missing from this list originally because it was dropped from prod when this was written).
// Guarded: the table is push-only and may legitimately be absent on some deployments.
$nOutlay = 0;
try {
    $nOutlay = (int) $pdo->query('SELECT COUNT(*) FROM usa_award_outlay_month o JOIN usa_award a ON a.award_id=o.award_id JOIN _drop d ON d.uei=a.recipient_uei')->fetchColumn();
    $total += $nOutlay; if ($nOutlay) out(sprintf('  %-30s %d', 'usa_award_outlay_month', $nOutlay));
} catch (\PDOException $e) { /* table absent on this deployment — nothing to prune */ }
// Orphan sweep preview: rows whose parent award is ALREADY gone. These accumulate independently
// of this prune — sync_usa DELETEs+reinserts a recipient's awards nightly, and any award the API
// stops returning leaves its month rows stranded and unjoinable.
$nOrphTxn = (int) $pdo->query('SELECT COUNT(*) FROM usa_award_txn_month t LEFT JOIN usa_award a ON a.award_id=t.award_id WHERE a.award_id IS NULL')->fetchColumn();
$nOrphOut = 0;
try { $nOrphOut = (int) $pdo->query('SELECT COUNT(*) FROM usa_award_outlay_month o LEFT JOIN usa_award a ON a.award_id=o.award_id WHERE a.award_id IS NULL')->fetchColumn(); } catch (\PDOException $e) {}
$total += $nOrphTxn + $nOrphOut;
if ($nOrphTxn || $nOrphOut) out(sprintf('  %-30s %d (txn %d / outlay %d)', 'orphan month-rows', $nOrphTxn + $nOrphOut, $nOrphTxn, $nOrphOut));
foreach ($byUei as $t) { $n = $countByUei($t, 'uei'); $total += $n; if ($n) out(sprintf('  %-30s %d', $t, $n)); }
out(sprintf('  %-30s %d', 'TOTAL rows', $total));

if (!$apply) {
    out("\nDry-run only. Re-run with --apply to perform the prune.");
    foreach (['_keep','_drop','_drop_reports'] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
    out(sprintf('Elapsed %.1fs', microtime(true) - $t0));
    return;
}

// ---- APPLY ----
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($byReport as $t) {
    $pdo->exec("DELETE t FROM `$t` t JOIN _drop_reports r ON r.report_id = t.report_id");
}
// Month tables FIRST (they join through usa_award, so they must go before the parent rows),
// then usa_award itself.
$pdo->exec('DELETE t FROM usa_award_txn_month t JOIN usa_award a ON a.award_id=t.award_id JOIN _drop d ON d.uei=a.recipient_uei');
try {
    $pdo->exec('DELETE o FROM usa_award_outlay_month o JOIN usa_award a ON a.award_id=o.award_id JOIN _drop d ON d.uei=a.recipient_uei');
} catch (\PDOException $e) { /* outlay table absent on this deployment */ }
$pdo->exec('DELETE a FROM usa_award a JOIN _drop d ON d.uei = a.recipient_uei');
// Orphan sweep: month rows whose parent award no longer exists (left by this prune's earlier
// runs, and by sync_usa's nightly DELETE+reinsert churn). Unjoinable and invisible to the app —
// pure quota waste, and the only thing keeping the outlay table scoped over time.
$pdo->exec('DELETE t FROM usa_award_txn_month t LEFT JOIN usa_award a ON a.award_id=t.award_id WHERE a.award_id IS NULL');
try {
    $pdo->exec('DELETE o FROM usa_award_outlay_month o LEFT JOIN usa_award a ON a.award_id=o.award_id WHERE a.award_id IS NULL');
} catch (\PDOException $e) { /* outlay table absent on this deployment */ }
foreach ($byUei as $t) {
    $pdo->exec("DELETE t FROM `$t` t JOIN _drop d ON d.uei = t.uei");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

foreach (['_keep','_drop','_drop_reports'] as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
out(sprintf("\nAPPLIED. %d rows deleted across %d entities. Elapsed %.1fs", $total, $dropCount, microtime(true) - $t0));
out('Next: rebuild entity directory + rescore so derived surfaces match, then verify.');
