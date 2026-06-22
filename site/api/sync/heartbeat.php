<?php
declare(strict_types=1);

/**
 * AERO — nightly run heartbeat (CLI). Called once at the end of the nightly pipeline.
 *
 *   php heartbeat.php ok   [message]    — the pipeline completed
 *   php heartbeat.php fail [message]    — the pipeline aborted
 *
 * Two purposes:
 *   1. Records a sync_log row (source 'nightly') so the Data Status console can show
 *      "last successful nightly" and flag a stall — making a stopped/failing nightly
 *      VISIBLE instead of letting per-source badges slowly age (the gap that hid a
 *      3-day outage in June 2026).
 *   2. If HEARTBEAT_URL is set, pings an external dead-man's-switch monitor
 *      (e.g. a healthchecks.io check): the base URL on success, BASE/fail on failure.
 *      That monitor alerts on a failed run AND on a MISSED run (no ping), so a nightly
 *      that silently stops running is caught proactively.
 *
 * Best-effort by design: never throws, never blocks/fails the run it reports on.
 */

$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
Env::load(dirname($root, 2) . '/.env');   // above web root (prod; first-set wins)
Env::load(dirname($root) . '/.env');      // repo root (local dev)

$state = (($argv[1] ?? 'ok') === 'fail') ? 'failed' : 'ok';
$msg   = isset($argv[2]) ? substr((string) $argv[2], 0, 250) : null;

// 1. DB heartbeat (best-effort)
try {
    Db::connect()->prepare(
        "INSERT INTO sync_log (source, table_name, status, message, started_at, finished_at)
         VALUES ('nightly', NULL, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    )->execute([$state, $msg]);
} catch (Throwable $e) {
    fwrite(STDERR, 'heartbeat: DB write failed: ' . $e->getMessage() . "\n");
}

// 2. external monitor ping (best-effort): success -> base URL; failure -> base/fail
$url = (string) Env::get('HEARTBEAT_URL', '');
if ($url !== '') {
    $ping = $state === 'ok' ? $url : rtrim($url, '/') . '/fail';
    $ch = curl_init($ping);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POSTFIELDS     => $msg ?? '',   // healthchecks.io logs the body
        CURLOPT_POST           => true,
    ]);
    curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err !== '') fwrite(STDERR, "heartbeat: monitor ping failed: $err\n");
}

echo "heartbeat: $state" . ($msg ? " ($msg)" : '') . "\n";
