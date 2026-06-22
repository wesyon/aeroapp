<?php
declare(strict_types=1);
/**
 * GET /api/deployments — the deployment log rendered on the Settings screen.
 * deploy.ps1 maintains api/deployments.json (newest first: date, commit, change
 * summaries from git) and ships it with each deploy; this just serves it.
 */

$file = dirname(__DIR__) . '/deployments.json';
$log = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
if (!is_array($log)) $log = [];
foreach ($log as &$e) {
    $e['changes'] = array_values((array) ($e['changes'] ?? []));   // PS5.1 collapses 1-element arrays
}
unset($e);

json_out(['deployments' => $log]);
