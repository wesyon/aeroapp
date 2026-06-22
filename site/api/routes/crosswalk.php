<?php
declare(strict_types=1);
/**
 * GET  /api/crosswalk            — list the state/UEI crosswalk.
 * POST /api/crosswalk            — save one state's UEIs + note  {state_code, ueis, note}.
 *
 * NOTE: the POST (write) path has no per-user auth, so it is restricted to the local
 * console (is_local_request) — the same gate the admin console uses. In prod
 * (APP_ENV=prod) it is disabled entirely.
 */

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!is_local_request()) {
        json_out(['error' => 'Editing the crosswalk is available only from the local AERO install.'], 403);
    }
    // Same anti-CSRF rule as admin run: a browser-supplied Origin must be local
    // (a hostile page can fire a no-preflight text/plain POST whose body is JSON).
    $reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($reqOrigin !== '' && !preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#i', $reqOrigin)) {
        json_out(['error' => 'cross-origin crosswalk writes are not allowed'], 403);
    }
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $code = strtoupper(trim((string) ($body['state_code'] ?? '')));
    if (!preg_match('/^[A-Z]{2}$/', $code)) json_out(['error' => 'state_code (2 letters) required'], 400);

    // accept newline- or comma-separated UEIs; keep only valid 12-char alphanumerics
    $ueis = [];
    foreach (preg_split('/[\s,]+/', (string) ($body['ueis'] ?? '')) as $u) {
        $u = strtoupper(trim($u));
        if ($u !== '' && preg_match('/^[A-Z0-9]{12}$/', $u)) $ueis[$u] = true;
    }
    $note = trim((string) ($body['note'] ?? ''));

    $stmt = $pdo->prepare(
        "INSERT INTO state_uei (state_code, label, ueis, note, updated_at) VALUES (?,?,?,?,UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE ueis=VALUES(ueis), note=VALUES(note), updated_at=VALUES(updated_at)"
    );
    $stmt->execute([$code, $body['label'] ?? null, implode("\n", array_keys($ueis)), $note ?: null]);
    json_out(['ok' => true, 'state_code' => $code, 'ueis' => array_keys($ueis), 'count' => count($ueis), 'updated_at' => gmdate('Y-m-d')]);
}

// GET — list in canonical US ordering (states alphabetically + DC inline, territories last)
$order = "FIELD(state_code,'AL','AK','AZ','AR','CA','CO','CT','DE','DC','FL','GA','HI','ID','IL','IN','IA',"
       . "'KS','KY','LA','ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH',"
       . "'OK','OR','PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY','PR','VI','GU','AS','MP')";
$rows = $pdo->query("SELECT state_code, label, ueis, note, updated_at FROM state_uei ORDER BY $order")->fetchAll();
$rows = array_map(fn ($r) => [
    'state_code' => $r['state_code'],
    'label'      => $r['label'],
    'ueis'       => $r['ueis'] ?? '',
    'note'       => $r['note'],
    'updated_at' => $r['updated_at'] ? substr($r['updated_at'], 0, 10) : null,
], $rows);

json_out(['rows' => $rows, 'count' => count($rows)]);
