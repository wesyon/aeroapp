<?php
declare(strict_types=1);

/**
 * AERO — nightly watchdog (CLI). Self-contained proactive alert, NO third-party
 * monitor (deliberate: keeps the eventual federal ATO surface small — email to an
 * admin via the host MTA only).
 *
 *   php watchdog.php            — alert ALERT_EMAIL if the last nightly is unhealthy
 *   php watchdog.php --force    — send a test email regardless (verify delivery)
 *
 * Runs at the TOP of aero_nightly.sh, before the load-bearing work — i.e. before the
 * point the shared host tends to SIGKILL the run. That timing is the whole trick: a
 * nightly that was reaped or failed last night never logs its own EXIT-trap heartbeat
 * (SIGKILL can't be trapped), but THIS morning's run fires reliably from cron and
 * reports the gap as its first act. So the common failure (job killed mid-run while
 * cron keeps firing) is caught with no extra cron entry to schedule.
 *
 * Coverage gap (by design): if the nightly stops STARTING entirely — total cron/host
 * death — nothing on the host runs, so nothing can alert. That case belongs to
 * platform-level infrastructure monitoring, not app code. See [[nightly-heartbeat]].
 *
 * Best-effort: never throws, never blocks the run it guards.
 */

$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
Env::load(dirname($root, 2) . '/.env');   // above web root (prod; first-set wins)
Env::load(dirname($root) . '/.env');      // repo root (local dev)

$force   = in_array('--force', $argv, true);
$maxAge  = (int) (Env::get('WATCHDOG_MAX_AGE_HOURS', '30') ?? '30');  // healthy daily run is ~24h old at next start
$to      = (string) Env::get('ALERT_EMAIL', '');
$from    = (string) Env::get('ALERT_FROM', 'no-reply@aeroapp.online');

// Most recent nightly heartbeat (written by heartbeat.php).
try {
    $row = Db::connect()->query(
        "SELECT status, message, finished_at,
                TIMESTAMPDIFF(HOUR, finished_at, UTC_TIMESTAMP()) AS age_h
           FROM sync_log
          WHERE source = 'nightly'
          ORDER BY finished_at DESC
          LIMIT 1"
    )->fetch();
} catch (Throwable $e) {
    fwrite(STDERR, 'watchdog: DB read failed: ' . $e->getMessage() . "\n");
    exit(1);   // a watchdog that can't read the DB is itself a problem worth a non-zero rc
}

$problem = null;
if (!$row) {
    $problem = 'No nightly heartbeat has ever been recorded.';
} elseif ($row['status'] !== 'ok') {
    $problem = "The last nightly ended '{$row['status']}' at {$row['finished_at']} UTC"
             . ($row['message'] ? " — {$row['message']}" : '') . '.';
} elseif ((int) $row['age_h'] >= $maxAge) {
    $problem = "The last successful nightly was {$row['finished_at']} UTC — {$row['age_h']}h ago "
             . "(threshold {$maxAge}h). The run is being killed before completion, or the scheduler stopped.";
}

if (!$problem && !$force) {
    echo "watchdog: ok (last nightly {$row['finished_at']} UTC, {$row['age_h']}h ago)\n";
    exit(0);
}

$subject = $force
    ? 'AERO watchdog: test alert (delivery check)'
    : 'AERO ALERT: nightly sync needs attention';
$body = ($problem ?? 'Forced test — the nightly is currently healthy.') . "\n\n"
      . "Host:          aeroapp.online\n"
      . "Checked (UTC): " . gmdate('Y-m-d H:i:s') . "\n"
      . ($row
          ? "Last nightly:  {$row['status']} @ {$row['finished_at']} UTC ({$row['age_h']}h ago)\n"
          : "Last nightly:  (none on record)\n")
      . "\nNext steps: open Settings > Data Status, then on the host inspect\n"
      . "  ~/aero_nightly.log   and   SELECT * FROM sync_log ORDER BY finished_at DESC;\n";

if ($to === '') {
    fwrite(STDERR, "watchdog: ALERT_EMAIL not set in .env — cannot send. Problem: "
                 . ($problem ?? '(forced test)') . "\n");
    exit(1);
}

// Host MTA (PHP sendmail_path -> hsendmail on Hostinger). From on-domain for deliverability.
$headers = "From: AERO <{$from}>\r\n"
         . "Content-Type: text/plain; charset=UTF-8\r\n";
$sent = @mail($to, $subject, $body, $headers);

echo $sent
    ? "watchdog: alert emailed to {$to}\n"
    : "watchdog: mail() returned false (MTA refused) — alert NOT sent to {$to}\n";
exit($sent ? 0 : 1);
