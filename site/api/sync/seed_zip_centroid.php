<?php
declare(strict_types=1);

/**
 * AERO — seed zip_centroid from the Census ZIP Code Tabulation Area (ZCTA) National
 * Gazetteer (public domain). The gazetteer is coordinate-only: GEOID (= 5-digit ZIP),
 * INTPTLAT, INTPTLONG. Powers the geographic map's recipient-dot layer (/api/geo_points).
 *
 *   php api/sync/seed_zip_centroid.php [path-or-url]
 *
 * With no argument it downloads the latest known Census gazetteer. Accepts a local .txt/.csv,
 * a local .zip, or an http(s) URL (zip or text). Idempotent (REPLACE INTO); creates the table
 * if the migration hasn't been applied yet.
 */

$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
Env::load(dirname($root, 2) . '/.env');   // above web root (prod; first-set wins)
Env::load(dirname($root) . '/.env');      // repo root (local dev)
$pdo = Db::connect();

$DEFAULT = 'https://www2.census.gov/geo/docs/maps-data/data/gazetteer/2024_Gazetteer/2024_Gaz_zcta_national.zip';
$src = $argv[1] ?? $DEFAULT;

$tmp = [];
$cleanup = static function () use (&$tmp) { foreach ($tmp as $f) @unlink($f); };

// resolve $src (url / zip / text) down to a readable tab-delimited text file
$path = $src;
if (preg_match('#^https?://#i', $src)) {
    fwrite(STDERR, "downloading $src ...\n");
    $ctx = stream_context_create(['http' => ['timeout' => 180, 'user_agent' => 'AERO/1.0 (zip-centroid seed)']]);
    $data = @file_get_contents($src, false, $ctx);
    if ($data === false) { fwrite(STDERR, "download failed (no network?)\n"); exit(1); }
    $path = tempnam(sys_get_temp_dir(), 'gaz') . (preg_match('/\.zip$/i', $src) ? '.zip' : '.txt');
    file_put_contents($path, $data);
    $tmp[] = $path;
}
if (preg_match('/\.zip$/i', $path)) {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) { fwrite(STDERR, "cannot open zip $path\n"); $cleanup(); exit(1); }
    $inner = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $nm = $zip->getNameIndex($i);
        if (preg_match('/\.(txt|csv)$/i', (string) $nm)) { $inner = $nm; break; }
    }
    if ($inner === null) { fwrite(STDERR, "no .txt/.csv inside zip\n"); $cleanup(); exit(1); }
    $ext = tempnam(sys_get_temp_dir(), 'gaztxt');
    file_put_contents($ext, $zip->getFromName($inner));
    $zip->close();
    $tmp[] = $ext;
    $path = $ext;
}

$fh = fopen($path, 'r');
if ($fh === false) { fwrite(STDERR, "cannot read $path\n"); $cleanup(); exit(1); }

// the gazetteer is tab-delimited; header names carry trailing spaces (e.g. "INTPTLONG       ")
$header = fgetcsv($fh, 0, "\t");
$idx = [];
foreach ($header as $i => $h) $idx[strtoupper(trim((string) $h))] = $i;
$gi = $idx['GEOID'] ?? 0;
$la = $idx['INTPTLAT'] ?? null;
$lo = $idx['INTPTLONG'] ?? ($idx['INTPTLON'] ?? null);
if ($la === null || $lo === null) { fwrite(STDERR, "missing INTPTLAT/INTPTLONG header\n"); $cleanup(); exit(1); }

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS zip_centroid (
        zip CHAR(5) NOT NULL, lat DECIMAL(9,6) NOT NULL, lng DECIMAL(9,6) NOT NULL,
        city VARCHAR(100) NULL, state CHAR(2) NULL,
        PRIMARY KEY (zip), KEY idx_zipcentroid_state (state)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

$st = $pdo->prepare("REPLACE INTO zip_centroid (zip, lat, lng) VALUES (?, ?, ?)");
$pdo->beginTransaction();
$n = 0;
while (($row = fgetcsv($fh, 0, "\t")) !== false) {
    $zip = str_pad(trim((string) ($row[$gi] ?? '')), 5, '0', STR_PAD_LEFT);
    if (!preg_match('/^\d{5}$/', $zip)) continue;
    $lat = trim((string) ($row[$la] ?? ''));
    $lng = trim((string) ($row[$lo] ?? ''));
    if ($lat === '' || $lng === '' || !is_numeric($lat) || !is_numeric($lng)) continue;
    $st->execute([$zip, (float) $lat, (float) $lng]);
    $n++;
}
$pdo->commit();
fclose($fh);
$cleanup();
echo "seeded $n zip centroids into zip_centroid\n";
