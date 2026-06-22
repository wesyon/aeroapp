<?php
declare(strict_types=1);

/**
 * AERO — SAM seed (CLI), official Public Extracts via the Extracts Download API.
 *   - sam_exclusion: Exclusions Public Extract (CSV in a ZIP, one API call)
 *   - sam_entity:    Entity Public Monthly Extract (pipe-delimited .dat), filtered
 *                    to UEIs in our entity hub. NAICS/business types are not in the
 *                    public extract, so those child tables are left empty.
 *
 * Options: --only=exclusions|entities, --file=PATH (pre-downloaded exclusions CSV),
 *          --entityfile=PATH (pre-downloaded entity .dat).
 *
 * Usage:
 *   php sync_sam.php                       # exclusions + entities, official
 *   php sync_sam.php --only=exclusions
 */

$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
Env::load(dirname($root, 2) . '/.env');   // above web root (prod; first-set wins)
Env::load(dirname($root) . '/.env');      // repo root (local dev)

function s($v): ?string { if ($v === null) return null; $v = trim((string) $v); return $v === '' ? null : $v; }
function uei($v): ?string { $v = s($v); return ($v !== null && preg_match('/^[A-Za-z0-9]{12}$/', $v)) ? $v : null; }
function d($v): ?string {
    $v = s($v); if ($v === null) return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return substr($v, 0, 10);
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m)) return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[1], (int)$m[2]);
    $t = strtotime($v); return $t ? date('Y-m-d', $t) : null;
}

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$source = $args['source'] ?? 'extract';
$only   = $args['only'] ?? null;
if ($source !== 'extract') {
    fwrite(STDERR, "Only --source=extract is supported (official SAM Public Extracts).\n");
    exit(1);
}

$pdo = Db::connect();

// One run at a time: both loaders DELETE their table before reloading.
if (!(int) $pdo->query("SELECT GET_LOCK('aero_sync_sam', 0)")->fetchColumn()) {
    fwrite(STDERR, "Another sync_sam.php run is already active; exiting.\n");
    exit(1);
}

/** sync_log entry so the console's partials panel sees skipped rows. */
function sam_log(PDO $pdo, string $table, int $rows, int $errs, ?string $firstErr, string $start): void
{
    $pdo->prepare(
        "INSERT INTO sync_log (source, scope, table_name, rows_upserted, status, message, started_at, finished_at)
         VALUES ('sam', 'extract', :t, :rows, :status, :msg, :start, UTC_TIMESTAMP())"
    )->execute([':t' => $table, ':rows' => $rows,
                ':status' => $errs ? 'partial' : 'ok',
                ':msg' => $errs ? "$errs rows skipped: " . substr((string) $firstErr, 0, 200) : null,
                ':start' => $start]);
}

$up  = new Upserter($pdo);

if ($only !== 'entities') {
    seed_exclusions_extract($pdo, $up, $args);
}
if ($only !== 'exclusions') {
    seed_entities_extract($pdo, $up, $args);
}
echo "Done.\n";
return;

// field helpers for the pipe-delimited entity .dat (no header)
function dat_s(array $p, int $i): ?string { $v = isset($p[$i]) ? trim($p[$i]) : ''; return $v === '' ? null : $v; }
function dat_d(array $p, int $i): ?string {
    $v = dat_s($p, $i);
    return ($v !== null && preg_match('/^(\d{4})(\d{2})(\d{2})$/', $v, $m)) ? "$m[1]-$m[2]-$m[3]" : null;
}
function dat_mmdd(array $p, int $i): ?string {
    $v = dat_s($p, $i);
    return ($v !== null && preg_match('/^(\d{2})(\d{2})$/', $v, $m)) ? "$m[1]/$m[2]" : $v;
}

/**
 * Seed sam_entity from the official SAM Entity Public Monthly Extract (.dat,
 * pipe-delimited, no header). Field positions were reverse-engineered against the
 * per-record entity API. Only entities in our `entity` hub are loaded. The public
 * extract has no NAICS, so sam_entity_naics / sam_business_type are left empty.
 */
function seed_entities_extract(PDO $pdo, Upserter $up, array $args): void
{
    $dat = isset($args['entityfile']) ? (string) $args['entityfile'] : find_entity_extract();
    if (!is_file($dat)) {
        throw new RuntimeException("Entity extract .dat not found: $dat");
    }
    // only keep entities we actually track (FAC auditees etc.)
    $ueis = [];
    $q = $pdo->query("SELECT uei FROM entity");
    while (($u = $q->fetchColumn()) !== false) {
        $ueis[$u] = true;
    }
    $statusMap = ['A' => 'Active', 'E' => 'Expired', 'D' => 'Deleted'];
    $start = gmdate('Y-m-d H:i:s');

    // ATOMIC reload: the delete and every insert share one transaction, so a failed
    // run (bad download, parse error) keeps the previous data instead of leaving the
    // table partially loaded. ~52k rows is comfortably within one InnoDB txn.
    // 'ID Assigned'/'Not Found' rows come from sync_sam_unregistered.php, not this
    // extract (which only holds actual registrations) — keep them; if such an entity
    // has since registered, the extract's upsert below overwrites its row anyway.
    $pdo->beginTransaction();
    $pdo->exec("DELETE FROM sam_entity
                WHERE registration_status IS NULL
                   OR registration_status NOT IN ('ID Assigned','Not Found')"); // cascades to naics + business_type
    $entFlag = $pdo->prepare("UPDATE entity SET has_sam=1, last_seen=UTC_TIMESTAMP() WHERE uei=:uei");

    $fh = fopen($dat, 'r');
    $rows = 0; $errs = 0; $firstErr = null;
    while (($line = fgets($fh)) !== false) {
        if (strncmp($line, 'BOF', 3) === 0) {
            continue; // beginning-of-file header record
        }
        $p   = explode('|', rtrim($line, "\r\n"));
        $uei = $p[0] ?? '';
        if (strlen($uei) !== 12 || !isset($ueis[$uei])) {
            continue;
        }
        try {
            $up->insert('sam_entity', [
                'uei'                          => $uei,
                'legal_business_name'          => dat_s($p, 11),
                'dba_name'                     => dat_s($p, 12),
                'cage_code'                    => dat_s($p, 3),
                'registration_status'          => $statusMap[$p[5] ?? ''] ?? dat_s($p, 5),
                'registration_date'            => dat_d($p, 7),
                'registration_expiration_date' => dat_d($p, 8),
                'last_update_date'             => dat_d($p, 9),
                'activation_date'              => dat_d($p, 10),
                'purpose_of_registration_code' => dat_s($p, 6),
                'state_of_incorporation'       => dat_s($p, 28),
                'country_of_incorporation'     => dat_s($p, 29),
                'physical_address_line1'       => dat_s($p, 15),
                'physical_address_city'        => dat_s($p, 17),
                'physical_address_state'       => dat_s($p, 18),
                'physical_address_zip'         => dat_s($p, 19),
                'physical_address_country'     => dat_s($p, 21),
                'congressional_district'       => dat_s($p, 22),
                'entity_start_date'            => dat_d($p, 24),
                'fiscal_year_end_close_date'   => dat_mmdd($p, 25),
                'last_synced'                  => gmdate('Y-m-d H:i:s'),
            ]);
            $entFlag->execute([':uei' => $uei]);
            $rows++;
        } catch (Throwable $e) {
            $errs++; $firstErr ??= $e->getMessage();
        }
    }
    fclose($fh);
    if ($rows === 0) {                       // format change / empty file: keep old data
        $pdo->rollBack();
        throw new RuntimeException("entity extract parsed 0 usable rows — aborted, previous data kept ($dat)");
    }
    $pdo->commit();

    // Reconcile the hub: clear stale has_sam, drop entities that no longer have ANY
    // source. Checks usa_recipient existence directly (not just the has_usa flag) so
    // the entity cascade can never wipe a recipient's cached USAspending data.
    $pdo->exec("UPDATE entity SET has_sam=0 WHERE has_sam=1 AND uei NOT IN (SELECT uei FROM sam_entity)");
    $pdo->exec("DELETE FROM entity WHERE has_fac=0 AND has_usa=0 AND has_sam=0 AND has_addl=0
                AND uei NOT IN (SELECT uei FROM usa_recipient)");

    sam_log($pdo, 'sam_entity', $rows, $errs, $firstErr, $start);
    printf("  sam_entity   %6d entities (official extract)%s\n", $rows,
        $errs ? "  ($errs skipped: " . substr((string) $firstErr, 0, 70) . ')' : '');
    fwrite(STDERR, "  note: NAICS/business types are not in the public extract; "
        . "sam_entity_naics & sam_business_type left empty (enrich per-UEI later)\n");
}

/** Locate a FRESH staged entity .dat (newest, under 25 days — the extract is
 *  monthly, so anything older is superseded), or download the current one. The
 *  old behavior returned any staged file forever, so the console said "updated
 *  today" while silently reloading a months-old extract. */
function find_entity_extract(): string
{
    $dir = dirname(__DIR__, 2) . '/csv/sam';
    $cands = glob("$dir/SAM_PUBLIC_MONTHLY_*.dat") ?: [];
    usort($cands, fn ($a, $b) => filemtime($b) <=> filemtime($a));
    if ($cands && filemtime($cands[0]) > time() - 25 * 86400) {
        return $cands[0];
    }
    $key  = Env::require('SAM_API_KEY');
    $base = rtrim(Env::get('SAM_BASE_URL', 'https://api.sam.gov'), '/');
    @mkdir($dir, 0777, true);
    $zip = "$dir/entity_extract.zip";
    echo "Downloading SAM Entity Public Monthly Extract (~145MB)...\n";
    $fp = fopen($zip, 'w');
    $ch = curl_init("$base/data-services/v1/extracts?api_key=$key&fileType=ENTITY&sensitivity=PUBLIC&frequency=MONTHLY");
    curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_TIMEOUT => 600, CURLOPT_USERAGENT => 'PostmanRuntime/7.39.0', CURLOPT_FOLLOWLOCATION => true]);
    $ca = ini_get('curl.cainfo'); if ($ca && is_file($ca)) curl_setopt($ch, CURLOPT_CAINFO, $ca);
    $ok = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch); fclose($fp);
    if (!$ok || $code >= 400) {
        throw new RuntimeException("Entity extract download failed (HTTP $code)");
    }
    $za = new ZipArchive();
    if ($za->open($zip) !== true) {
        throw new RuntimeException("Could not open entity ZIP");
    }
    $inner = $za->getNameIndex(0);
    $za->extractTo($dir);
    $za->close();
    return "$dir/$inner";
}

/** Download (or reuse) the official Exclusions Public Extract and load sam_exclusion. */
function seed_exclusions_extract(PDO $pdo, Upserter $up, array $args): void
{
    $csvPath = isset($args['file']) ? (string) $args['file'] : download_exclusions_extract();
    if (!is_file($csvPath)) {
        throw new RuntimeException("Exclusions CSV not found: $csvPath");
    }
    $fh = fopen($csvPath, 'r');
    $header = fgetcsv($fh);
    $idx = array_flip($header); // column name -> position
    $get = fn (array $row, string $col) => isset($idx[$col]) ? ($row[$idx[$col]] ?? null) : null;
    $start = gmdate('Y-m-d H:i:s');

    // ATOMIC reload (delete + inserts in one transaction): a failed run keeps the
    // previous exclusion list instead of leaving the debarment data half-loaded.
    $pdo->beginTransaction();
    $pdo->exec("DELETE FROM sam_exclusion"); // fully sourced from this extract
    $rows = 0; $errs = 0; $firstErr = null;
    while (($row = fgetcsv($fh)) !== false) {
        try {
            $isIndiv = (s($get($row, 'Classification')) === 'Individual');
            $name = s($get($row, 'Name'));
            $up->insert('sam_exclusion', [
                'uei_sam'               => uei($get($row, 'Unique Entity ID')),
                'cage_code'             => s($get($row, 'CAGE')),
                'npi'                   => s($get($row, 'NPI')),
                'classification_type'   => s($get($row, 'Classification')),
                'exclusion_type'        => s($get($row, 'Exclusion Type')),
                'exclusion_program'     => s($get($row, 'Exclusion Program')),
                'excluding_agency_name' => s($get($row, 'Excluding Agency')),
                'entity_name'           => $name,
                'prefix'                => s($get($row, 'Prefix')),
                'first_name'            => s($get($row, 'First')),
                'middle_name'           => s($get($row, 'Middle')),
                'last_name'             => $isIndiv ? s($get($row, 'Last')) : null,
                'suffix'                => s($get($row, 'Suffix')),
                'create_date'           => d($get($row, 'Creation_Date')),
                'activate_date'         => d($get($row, 'Active Date')),
                'termination_date'      => d($get($row, 'Termination Date')),
                'record_status'         => s($get($row, 'Record Status')),
                'city'                  => s($get($row, 'City')),
                'state_or_province'     => s($get($row, 'State / Province')),
                'zip_code'              => s($get($row, 'Zip Code')),
                'country_code'          => s($get($row, 'Country')),
                'last_synced'           => gmdate('Y-m-d H:i:s'),
            ]);
            $rows++;
        } catch (Throwable $e) {
            $errs++; $firstErr ??= $e->getMessage();
        }
    }
    fclose($fh);
    if ($rows === 0) {                       // format change / empty file: keep old data
        $pdo->rollBack();
        throw new RuntimeException("exclusions extract parsed 0 usable rows — aborted, previous data kept ($csvPath)");
    }
    $pdo->commit();
    sam_log($pdo, 'sam_exclusion', $rows, $errs, $firstErr, $start);
    printf("  sam_exclusion %6d records (official extract)%s\n", $rows,
        $errs ? "  ($errs skipped: " . substr((string) $firstErr, 0, 80) . ')' : '');
}

/** Pull the Exclusions Public Extract ZIP via the Extracts API, unzip, return CSV path. */
function download_exclusions_extract(): string
{
    $key  = Env::require('SAM_API_KEY');
    $base = rtrim(Env::get('SAM_BASE_URL', 'https://api.sam.gov'), '/');
    $url  = "$base/data-services/v1/extracts?api_key=$key&fileType=EXCLUSION";
    $dir  = dirname(__DIR__, 2) . '/csv/sam';
    @mkdir($dir, 0777, true);
    $zip  = "$dir/exclusions_extract.zip";

    echo "Downloading SAM Exclusions Public Extract...\n";
    $fp = fopen($zip, 'w');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE      => $fp,
        CURLOPT_TIMEOUT   => 180,
        CURLOPT_USERAGENT => 'PostmanRuntime/7.39.0',
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $ca = ini_get('curl.cainfo'); if ($ca && is_file($ca)) curl_setopt($ch, CURLOPT_CAINFO, $ca);
    $ok = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch); fclose($fp);
    if (!$ok || $code >= 400) {
        throw new RuntimeException("Extract download failed (HTTP $code)");
    }
    $za = new ZipArchive();
    if ($za->open($zip) !== true || $za->numFiles < 1) {
        throw new RuntimeException("Could not open extract ZIP");
    }
    $inner = $za->getNameIndex(0);
    $za->extractTo($dir);
    $za->close();
    return "$dir/$inner";
}

