<?php
declare(strict_types=1);

/**
 * AERO Analytics API — single front controller.
 * Routes registered in $handlers below (filters, grantee, finding, dashboard, admin, …).
 * Local dev:  php -S localhost:8000 api/index.php   (index.php is the router)
 * Hostinger:  public_html/api/ with .htaccess rewriting to index.php
 */

require __DIR__ . '/lib/Env.php';
require __DIR__ . '/lib/Db.php';
require __DIR__ . '/lib/Rules.php';   // shared deadline/lineage helpers used by routes
require __DIR__ . '/lib/Cache.php';   // atomic, UTF-8-safe disk cache (cache_put/cache_get)
// Prefer a .env ABOVE the web root (not servable even if .htaccess is ignored); fall
// back to one alongside api/. Env::load keeps the first value set, so above-root wins.
Env::load(dirname(__DIR__, 2) . '/.env');
Env::load(dirname(__DIR__) . '/.env');

header('Content-Type: application/json; charset=utf-8');
// CORS only matters for local dev (direct cross-origin hits to the PHP server). In
// prod the SPA is same-origin, so no Access-Control-Allow-Origin header is emitted.
// Reflect ONLY local dev origins (Vite :5173, direct localhost testing) — never `*`,
// which would let any web page the developer visits drive the local API cross-origin.
if (is_local_request()) {
    $corsOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#i', $corsOrigin)) {
        header('Access-Control-Allow-Origin: ' . $corsOrigin);
        header('Vary: Origin');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    }
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_out($data, int $code = 200): never
{
    http_response_code($code);
    // JSON_INVALID_UTF8_SUBSTITUTE: free-text fields (finding_text, notes) can carry
    // stray bytes; substitute rather than let json_encode return false (empty body).
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        http_response_code(500);
        $json = '{"error":"response encoding failed"}';
    }
    echo $json;
    exit;
}
/** comma-separated query param -> trimmed non-empty array */
function q_list(string $k): array
{
    $v = $_GET[$k] ?? '';
    if (!is_string($v) || trim($v) === '') return [];
    return array_values(array_filter(array_map('trim', explode(',', $v)), fn ($x) => $x !== ''));
}
function q_str(string $k): ?string
{
    $v = $_GET[$k] ?? null;
    return (is_string($v) && trim($v) !== '') ? trim($v) : null;
}
function q_int(string $k, int $default, int $min, int $max): int
{
    $v = isset($_GET[$k]) ? (int) $_GET[$k] : $default;
    return max($min, min($max, $v));
}

/**
 * Is this request from the local console (dev, or a Cloudflare-tunnelled localhost)?
 * The admin console, crosswalk writes, the signals console, and cache-busting (?fresh)
 * are restricted to it.
 *
 * FAIL-CLOSED on APP_ENV: the write/admin surface is enabled ONLY when APP_ENV explicitly
 * names a dev environment ('local' or 'dev'). Any other value — 'prod', blank, unset, a
 * typo, or a failed .env load — locks it, regardless of source IP. This matters once a
 * same-host reverse proxy (a gov load balancer, a Cloudflare tunnel) forwards over loopback
 * and REMOTE_ADDR reads as 127.0.0.1 for every request: a missing or misspelled env var can
 * then never re-open the surface. Local dev therefore REQUIRES APP_ENV=local (see .env.example).
 */
function is_local_request(): bool
{
    $env = strtolower((string) Env::get('APP_ENV', ''));
    if ($env !== 'local' && $env !== 'dev') {
        return false;
    }
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

/**
 * Build the shared findings filter from year/state/agency params, returning
 * [$join, $where, $params]. Assumes the query aliases fac_findings as `f`; the
 * caller adds the fac_general join (aliased `g`) when a state filter is present.
 *
 * The agency filter is expressed as a JOIN against the (small) set of matching
 * finding keys from the bridge rather than a correlated EXISTS over every finding
 * — measured ~5x faster on the agency-filtered dashboard. Params are returned in
 * SQL textual order (join placeholders precede where placeholders).
 */
function findings_filter(): array
{
    $join = '';
    $joinParams = [];
    $where = [];
    $whereParams = [];
    $years = array_values(array_filter(array_map('intval', q_list('years'))));
    if ($years) {
        $where[] = 'f.audit_year IN (' . implode(',', array_fill(0, count($years), '?')) . ')';
        array_push($whereParams, ...$years);
    }
    if (($state = q_str('state')) !== null) {
        $where[] = 'g.auditee_state = ?';
        $whereParams[] = $state;
    }
    if (($agency = q_str('agency')) !== null) {   // 2-digit ALN agency prefix
        // prefix is denormalized onto the bridge; mirror the year filter inside the
        // derived set so it stays minimal (and consistent with the trend query,
        // which drops years before calling this).
        $bw = ['fb.federal_agency_prefix = ?'];
        $joinParams[] = $agency;
        if ($years) {
            $bw[] = 'fb.audit_year IN (' . implode(',', array_fill(0, count($years), '?')) . ')';
            array_push($joinParams, ...$years);
        }
        $join = ' JOIN (SELECT DISTINCT report_id, reference_number FROM fac_finding_awards fb WHERE '
              . implode(' AND ', $bw) . ') af'
              . ' ON af.report_id = f.report_id AND af.reference_number = f.reference_number';
    }
    return [$join, $where ? 'WHERE ' . implode(' AND ', $where) : '', array_merge($joinParams, $whereParams)];
}

/** prefix (2-digit ALN) -> agency name, from the assistance listings catalog. */
function agency_names(PDO $pdo): array
{
    $map = [];
    $sql = "SELECT SUBSTRING_INDEX(assistance_listing_id,'.',1) p, MAX(department) a "
         . "FROM assistance_listing GROUP BY p";
    foreach ($pdo->query($sql) as $r) {
        $map[$r['p']] = $r['a'];
    }
    return $map;
}

/**
 * Per-IP rate limit. The read API is public with no auth, and /grantee runs ~20
 * queries per hit — an unthrottled crawler walking UEIs would degrade the shared
 * host for everyone. Fixed window, file-backed (no APCu/redis on shared hosting),
 * FAIL-OPEN on any filesystem hiccup so the limiter can never take the site down.
 * Local console traffic is exempt (dev polling, tunnels). 80/min is ~5x a busy
 * human session (the Settings job poll is 15/min) while capping a scraper at
 * ~1.3 req/s sustained.
 */
function rate_limit(int $max = 80, int $window = 60): void
{
    if (is_local_request()) return;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip === '') return;
    $dir = __DIR__ . '/cache/ratelimit';
    @mkdir($dir, 0775, true);
    $file = $dir . '/' . sha1($ip);
    $now = time();
    $fh = @fopen($file, 'c+');
    if ($fh === false || !flock($fh, LOCK_EX)) {
        if ($fh !== false) fclose($fh);
        return;                                     // fail-open
    }
    $d = json_decode((string) stream_get_contents($fh), true) ?: [];
    if ($now - (int) ($d['start'] ?? 0) >= $window) $d = ['start' => $now, 'n' => 0];
    $d['n'] = (int) ($d['n'] ?? 0) + 1;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($d));
    flock($fh, LOCK_UN);
    fclose($fh);
    // opportunistic cleanup: counters are one small file per IP; sweep hourly-stale ones
    if (random_int(1, 200) === 1) {
        foreach ((glob("$dir/*") ?: []) as $f) {
            if (@filemtime($f) < $now - 3600) @unlink($f);
        }
    }
    if ($d['n'] > $max) {
        header('Retry-After: ' . max(1, (int) $d['start'] + $window - $now));
        json_out(['error' => 'rate limit exceeded, retry shortly'], 429);
    }
}
rate_limit();

try {
    $pdo = Db::connect();
} catch (Throwable $e) {
    error_log('AERO db connect failed: ' . $e->getMessage());
    json_out(['error' => 'database connection failed'], 500);
}

// resolve route: strip leading slash + optional "api/" prefix, drop query string
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$route = $_GET['route'] ?? preg_replace('#^/?(api/)?#', '', rtrim($path, '/'));

$handlers = [
    'filters'          => __DIR__ . '/routes/filters.php',
    'grantee'          => __DIR__ . '/routes/grantee.php',
    'finding'          => __DIR__ . '/routes/finding.php',
    'findings'         => __DIR__ . '/routes/findings.php',
    'finding_trends'   => __DIR__ . '/routes/finding_trends.php',
    'passthrough'      => __DIR__ . '/routes/passthrough.php',
    'dashboard'        => __DIR__ . '/routes/dashboard.php',
    'evaluation'       => __DIR__ . '/routes/evaluation.php',
    'repeats'          => __DIR__ . '/routes/repeats.php',
    'deployments'      => __DIR__ . '/routes/deployments.php',
    'admin'            => __DIR__ . '/routes/admin.php',
    'recipients'       => __DIR__ . '/routes/recipients.php',
    'geo_points'       => __DIR__ . '/routes/geo_points.php',
    'delinquency'      => __DIR__ . '/routes/delinquency.php',
    'opinions'         => __DIR__ . '/routes/opinions.php',
    'convergence'      => __DIR__ . '/routes/convergence.php',
    'subaward'         => __DIR__ . '/routes/subaward.php',
    'usa_awards'       => __DIR__ . '/routes/usa_awards.php',
    'crosswalk'        => __DIR__ . '/routes/crosswalk.php',
    'crosscheck'       => __DIR__ . '/routes/crosscheck.php',
    'signals'          => __DIR__ . '/routes/signals.php',
    'case_summary'     => __DIR__ . '/routes/case_summary.php',
];

if (!isset($handlers[$route])) {
    json_out(['error' => 'not found', 'route' => $route, 'available' => array_keys($handlers)], 404);
}

try {
    require $handlers[$route];   // handler uses $pdo + the q_*/json_out helpers, ends with json_out()
} catch (Throwable $e) {
    // Log details server-side; never leak internal messages (SQL, paths) to clients.
    error_log("AERO route '$route' failed: " . $e->getMessage());
    json_out(['error' => 'server error'], 500);
}
