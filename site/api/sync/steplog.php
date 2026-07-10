<?php
declare(strict_types=1);

/**
 * AERO — nightly step tracker (CLI). Records the nightly's current step to sync_log
 * (source='nightly_step', scope=step label) so the Data Status console can show a LIVE
 * run checklist — each step ⋯ pending → running → ✓ done (or 🔴 interrupted).
 *
 *   php steplog.php step "FAC incremental"   finalize the previous open step, start this one
 *   php steplog.php done                       finalize the last open step (run complete)
 *   php steplog.php fail "message"             mark the current open step failed
 *
 * A step whose row never leaves 'running' (the run was reaped mid-step) is surfaced as
 * INTERRUPTED by the same progress_at logic as the sync sub-jobs. Best-effort: never throws,
 * never fails the run it reports on. Only touches steps started in the last 60 min, so a
 * previous reaped run's dangling step isn't mislabelled 'ok' when tonight's run begins.
 */

$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
Env::load(dirname($root, 2) . '/.env');
Env::load(dirname($root) . '/.env');

$action = $argv[1] ?? '';
$label  = isset($argv[2]) ? mb_substr((string) $argv[2], 0, 40) : '';

try {
    $pdo = Db::connect();
    if ($action === 'fail') {
        $pdo->prepare(
            "UPDATE sync_log SET status='error', message=?, finished_at=UTC_TIMESTAMP()
             WHERE source='nightly_step' AND status='running'
               AND started_at > UTC_TIMESTAMP() - INTERVAL 60 MINUTE
             ORDER BY id DESC LIMIT 1"
        )->execute([mb_substr((string) ($argv[2] ?? ''), 0, 250)]);
    } else {
        // finalize the previous step of THIS run (started within the hour) as done
        $pdo->exec(
            "UPDATE sync_log SET status='ok', finished_at=UTC_TIMESTAMP()
             WHERE source='nightly_step' AND status='running'
               AND started_at > UTC_TIMESTAMP() - INTERVAL 60 MINUTE"
        );
        if ($action === 'step' && $label !== '') {
            $pdo->prepare(
                "INSERT INTO sync_log (source, scope, status, started_at, progress_at)
                 VALUES ('nightly_step', ?, 'running', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
            )->execute([$label]);
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'steplog: ' . $e->getMessage() . "\n");   // best-effort; never fail the run
}
