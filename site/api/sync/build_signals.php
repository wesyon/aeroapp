<?php
declare(strict_types=1);

/**
 * AERO — build the Signals indicator tables (CLI, LOCAL ONLY).
 *
 * Runs every implemented detector in lib/Signals.php and writes the hits to:
 *   signal_flag    — one row per detected (uei, code, scope) with cited evidence JSON
 *   signal_entity  — per-entity convergence rollup (powers the Triage worklist)
 * Curated allowlist (signal_suppression) is CREATEd IF NOT EXISTS and never dropped;
 * a flag matching it is written with suppressed=1 (kept for audit, hidden by default).
 *
 * Like build_subaward_edge.php: local-only, lock-guarded, ABORTS on an empty source so it
 * can never wipe a good prod copy. The derived tables reach prod via deploy.ps1 -PushTable;
 * the prod nightly does not call this.
 *
 * Usage:
 *   php build_signals.php
 *   php build_signals.php --stats   # print current table stats and exit
 */

$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
require $root . '/lib/Signals.php';
Env::load(dirname($root, 2) . '/.env');
Env::load(dirname($root) . '/.env');
$pdo = Db::connect();

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}

if (isset($args['stats'])) {
    if (!$pdo->query("SHOW TABLES LIKE 'signal_flag'")->fetchColumn()) { echo "signal_flag does not exist (run a build first)\n"; exit(0); }
    foreach ($pdo->query(
        "SELECT code, COUNT(*) n, SUM(suppressed) supp FROM signal_flag GROUP BY code ORDER BY n DESC") as $r) {
        printf("  %-24s %6s flags  (%s suppressed)\n", $r['code'], number_format((int) $r['n']), number_format((int) $r['supp']));
    }
    $e = $pdo->query("SELECT COUNT(*) ents, SUM(n_signals>=2) conv FROM signal_entity")->fetch(PDO::FETCH_ASSOC);
    printf("entities flagged: %s  (with >=2 signals: %s)\n", number_format((int) $e['ents']), number_format((int) $e['conv']));
    exit(0);
}

if (!(int) $pdo->query("SELECT GET_LOCK('aero_build_signals', 0)")->fetchColumn()) {
    fwrite(STDERR, "Another build_signals.php run is already active; exiting.\n");
    exit(0);
}

// SAFETY GATE: never run against an empty source (would publish an empty signal set to prod).
$entRows = (int) $pdo->query("SELECT COUNT(*) FROM entity")->fetchColumn();
if ($entRows === 0) {
    fwrite(STDERR, "entity is EMPTY — aborting without touching signal tables.\n");
    exit(1);
}

$startedAt = gmdate('Y-m-d H:i:s');
$t0 = microtime(true);
$detectedAt = date('Y-m-d H:i:s');

// curated allowlist — authored locally, shipped to prod; created once, never dropped.
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS signal_suppression (
        id BIGINT NOT NULL AUTO_INCREMENT,
        match_type  VARCHAR(16)  NOT NULL,          -- uei | address | certifier | auditor_ein | sponsor
        match_value VARCHAR(255) NOT NULL,
        code        VARCHAR(32)  NOT NULL DEFAULT '*',
        reason      VARCHAR(255) NOT NULL,
        source      VARCHAR(16)  NOT NULL DEFAULT 'manual',
        created_at  DATETIME     NOT NULL,
        PRIMARY KEY (id), KEY idx_supp_lookup (match_type, match_value)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// derived tables: DROP+CREATE so a schema tweak always takes locally (prod copy is replaced
// wholesale by deploy.ps1 -PushTable).
$pdo->exec("DROP TABLE IF EXISTS signal_flag");
$pdo->exec(
    "CREATE TABLE signal_flag (
        uei         CHAR(12)      NOT NULL,
        code        VARCHAR(32)   NOT NULL,
        scope       VARCHAR(160)  NOT NULL DEFAULT '',
        tier        TINYINT       NOT NULL,
        severity    VARCHAR(16)   NOT NULL,
        context     TINYINT(1)    NOT NULL DEFAULT 0,   -- broad lead; excluded from convergence ranking
        magnitude   DECIMAL(20,2) NULL,
        evidence    JSON          NOT NULL,
        suppressed  TINYINT(1)    NOT NULL DEFAULT 0,
        detected_at DATETIME      NOT NULL,
        PRIMARY KEY (uei, code, scope),
        KEY idx_sflag_code (code),
        KEY idx_sflag_tier (tier),
        KEY idx_sflag_live (suppressed, context, code)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$ins = $pdo->prepare(
    "INSERT IGNORE INTO signal_flag (uei, code, scope, tier, severity, context, magnitude, evidence, detected_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

$total = 0;
foreach (Signals::implemented() as $code) {
    $meta = Signals::REGISTRY[$code];
    $ctx = !empty($meta['context']) ? 1 : 0;
    try {
        $rows = Signals::detect($pdo, $code);
        $pdo->beginTransaction();
        $n = 0;
        foreach ($rows as $r) {
            if (empty($r['uei'])) continue;
            $ins->execute([
                $r['uei'], $code, (string) ($r['scope'] ?? ''), $meta['tier'], $meta['severity'], $ctx,
                $r['magnitude'], json_encode($r['evidence'], JSON_UNESCAPED_SLASHES), $detectedAt,
            ]);
            $n += $ins->rowCount();
        }
        $pdo->commit();
        $total += $n;
        printf("  %-24s %6s flags%s\n", $code, number_format($n), $ctx ? '  (context)' : '');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fwrite(STDERR, sprintf("  %-24s FAILED: %s\n", $code, $e->getMessage()));
    }
}

// apply suppression (Phase 0: UEI-level; address/certifier/sponsor matching lands in Phase 3
// with the auto-seeded affiliate allowlist).
$supp = (int) $pdo->exec(
    "UPDATE signal_flag sf
     JOIN signal_suppression s
       ON s.match_type = 'uei' AND s.match_value = sf.uei AND (s.code = '*' OR s.code = sf.code)
     SET sf.suppressed = 1");

// convergence rollup over LIVE flags only
$pdo->exec("DROP TABLE IF EXISTS signal_entity");
$pdo->exec(
    "CREATE TABLE signal_entity (
        uei          CHAR(12)    NOT NULL,
        n_signals    SMALLINT    NOT NULL,
        n_rule       SMALLINT    NOT NULL,
        n_network    SMALLINT    NOT NULL,
        n_stat       SMALLINT    NOT NULL,
        top_severity VARCHAR(16) NOT NULL,
        codes        JSON        NOT NULL,
        computed_at  DATETIME    NOT NULL,
        PRIMARY KEY (uei), KEY idx_sentity_n (n_signals), KEY idx_sentity_rule (n_rule)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec(
    "INSERT INTO signal_entity (uei, n_signals, n_rule, n_network, n_stat, top_severity, codes, computed_at)
     SELECT uei,
            COUNT(DISTINCT code),
            COUNT(DISTINCT CASE WHEN severity IN ('rule_violation','data_integrity') THEN code END),
            COUNT(DISTINCT CASE WHEN severity = 'network'    THEN code END),
            COUNT(DISTINCT CASE WHEN severity = 'statistical' THEN code END),
            CASE WHEN MAX(severity = 'rule_violation') THEN 'rule_violation'
                 WHEN MAX(severity = 'data_integrity') THEN 'data_integrity'
                 WHEN MAX(severity = 'network')        THEN 'network'
                 ELSE 'statistical' END,
            JSON_ARRAYAGG(code),
            '$detectedAt'
     FROM (SELECT DISTINCT uei, code, severity FROM signal_flag WHERE suppressed = 0 AND context = 0) d
     GROUP BY uei");

$st = $pdo->query("SELECT COUNT(*) ents, SUM(n_signals>=2) conv FROM signal_entity")->fetch(PDO::FETCH_ASSOC);
printf("built signal_flag: %s flags (%s suppressed) across %s entities (%s with >=2 signals) in %.1fs\n",
    number_format($total), number_format($supp), number_format((int) $st['ents']),
    number_format((int) $st['conv']), microtime(true) - $t0);

$pdo->prepare(
    "INSERT INTO sync_log (source, table_name, rows_upserted, status, started_at, finished_at)
     VALUES ('signals', 'signal_flag', ?, 'ok', ?, UTC_TIMESTAMP())"
)->execute([$total, $startedAt]);
