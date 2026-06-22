<?php
declare(strict_types=1);

/**
 * AERO — apply pending DB migrations (CLI).
 *
 * Applies migrations/*.sql in filename order, skipping files already recorded in
 * schema_migrations. Records each applied file so re-runs are no-ops. The same
 * convention runs on prod via deploy.ps1 (a remote shell loop), so write
 * migrations in SQL that BOTH engines accept (local MySQL 8.4, prod MariaDB 11.8)
 * — e.g. plain ADD COLUMN/ADD INDEX, no MariaDB-only IF NOT EXISTS.
 *
 * A migration file may hold multiple statements; it is applied with one exec()
 * (DDL auto-commits, so a mid-file failure can leave it partially applied — keep
 * migrations small and re-runnable after manual cleanup).
 *
 * Usage:
 *   php api/sync/apply_migrations.php           # apply all pending
 *   php api/sync/apply_migrations.php --status  # list applied/pending and exit
 */

$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
Env::load(dirname($root, 2) . '/.env');   // above web root (prod; first-set wins)
Env::load(dirname($root) . '/.env');      // repo root (local dev)

$pdo = Db::connect();
$dir = dirname($root) . '/migrations';

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        filename   VARCHAR(255) NOT NULL,
        applied_at DATETIME     NOT NULL,
        PRIMARY KEY (filename)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$applied = [];
foreach ($pdo->query("SELECT filename FROM schema_migrations") as $r) {
    $applied[$r['filename']] = true;
}

$files = glob($dir . '/*.sql') ?: [];
sort($files);

if (in_array('--status', $argv, true)) {
    foreach ($files as $f) {
        printf("  %-9s %s\n", isset($applied[basename($f)]) ? 'applied' : 'PENDING', basename($f));
    }
    exit;
}

$record = $pdo->prepare("INSERT INTO schema_migrations (filename, applied_at) VALUES (?, UTC_TIMESTAMP())");
$n = 0;
foreach ($files as $f) {
    $name = basename($f);
    if (isset($applied[$name])) continue;
    echo "Applying $name...\n";
    try {
        $pdo->exec((string) file_get_contents($f));
    } catch (Throwable $e) {
        // Name the offending file and stop. Migrations apply in order and may depend
        // on each other, so don't push past a failure onto a half-migrated DB. A
        // common cause is a migration missing from schema.sql's schema_migrations seed
        // (so it re-runs against a schema that already has its change) — add it there.
        // DDL auto-commits, so this file may be partially applied: fix the cause (or
        // the DB by hand), then re-run; already-applied files are skipped.
        fwrite(STDERR, "FAILED applying $name: " . $e->getMessage() . "\n");
        exit(1);
    }
    $record->execute([$name]);
    $n++;
}
echo $n ? "Done. $n migration(s) applied.\n" : "Nothing pending.\n";
