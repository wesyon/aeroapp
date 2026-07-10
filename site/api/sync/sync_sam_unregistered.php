<?php
declare(strict_types=1);

/**
 * AERO — SAM unregistered-entity enrichment (CLI).
 * https://open.gsa.gov/api/entity-api/
 *
 * The Entity Public Monthly Extract (sync_sam.php) only contains entities with
 * an actual SAM *registration*, so hub entities that were validated and issued
 * a UEI but never registered ("ID Assigned") have no sam_entity row and read as
 * blank in the UI. This script resolves every hub UEI missing from sam_entity
 * against the live Entity Management API and writes one of:
 *   - 'ID Assigned'         (samRegistered=No: UEI exists, never registered)
 *   - the real status        (registered after the extract was cut, or missed
 *                             by it — Active/Inactive etc.; flags has_sam)
 *   - 'Not Found'            (SAM's public API returns nothing for the UEI —
 *                             a typo'd UEI in the FAC filing, or a record
 *                             opted out of public display)
 * Rows carry last_synced, so "Not Found" means "as of that check".
 *
 * sync_sam.php's monthly reload preserves 'ID Assigned'/'Not Found' rows (and
 * upserts over them if the entity has since registered); rows this script wrote
 * for *registered* stragglers are deliberately NOT preserved — the extract is
 * authoritative for those, and this script re-adds any it still misses.
 *
 * UEIs are resolved in tilde-batched queries (~50 UEIs, size=10 pages), so a
 * full 7k-UEI backfill costs ~1,000 requests. Quota is shared with the other
 * SAM consumers; --pace (default 4.2s ≈ 860/hr) plus a quota-wall ride-out
 * (matching sync_subawards.php) keep it inside the key's limits. Resumable:
 * every written row leaves the missing-set, so a re-run continues where it died.
 *
 * Usage:
 *   php sync_sam_unregistered.php                  # resolve all missing UEIs
 *   php sync_sam_unregistered.php --limit=500      # cap this run
 *   php sync_sam_unregistered.php --uei=XXXXXXXXXXXX
 *   php sync_sam_unregistered.php --recheck=35     # also re-verify enriched rows
 *                                                  #   older than N days
 */

$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
require $root . '/lib/Http.php';
require $root . '/lib/RunLog.php';
require $root . '/lib/QuotaObs.php';
Env::load(dirname($root, 2) . '/.env');   // above web root (prod; first-set wins)
Env::load(dirname($root) . '/.env');      // repo root (local dev)

function s($v): ?string { if ($v === null) return null; $v = trim((string) $v); return $v === '' ? null : $v; }
function cl($v, int $n): ?string { $v = s($v); return $v === null ? $v : mb_substr($v, 0, $n); }
function dd($v): ?string { $v = s($v); if ($v === null) return null; $t = strtotime($v); return $t ? date('Y-m-d', $t) : null; }

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}

$pdo = Db::connect();
if (!(int) $pdo->query("SELECT GET_LOCK('aero_sync_sam_unreg', 0)")->fetchColumn()) {
    fwrite(STDERR, "Another sync_sam_unregistered.php run is already active; exiting.\n");
    exit(1);
}

$up     = new Upserter($pdo);
$key    = Env::require('SAM_API_KEY');
$base   = rtrim(Env::get('SAM_BASE_URL', 'https://api.sam.gov'), '/');
$paceUs = (int) (max(0.0, (float) ($args['pace'] ?? 4.2)) * 1e6);
$limit  = isset($args['limit']) ? max(1, (int) $args['limit']) : null;

// ---- the UEIs to resolve -----------------------------------------------------
if (isset($args['uei']) && preg_match('/^[A-Za-z0-9]{12}$/', (string) $args['uei'])) {
    $ueis = [strtoupper((string) $args['uei'])];
} else {
    $sql = "SELECT e.uei FROM entity e LEFT JOIN sam_entity s ON s.uei = e.uei WHERE s.uei IS NULL";
    if (isset($args['recheck']) && (int) $args['recheck'] > 0) {
        // also re-verify previously enriched rows past their shelf life: 'Not Found'
        // can become 'ID Assigned', and 'ID Assigned' can register between extracts
        $sql .= " UNION SELECT s2.uei FROM sam_entity s2
                  WHERE s2.registration_status IN ('ID Assigned','Not Found')
                    AND s2.last_synced < UTC_TIMESTAMP() - INTERVAL " . (int) $args['recheck'] . " DAY";
    }
    $ueis = $pdo->query($sql . " ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
}
if ($limit !== null) $ueis = array_slice($ueis, 0, $limit);
if (!$ueis) {
    echo "Nothing to resolve: every hub entity has a sam_entity row.\n";
    // Clean no-op so a caught-up night keeps the Data Status timestamp fresh (not "stale").
    RunLog::finish($pdo, null, 'sam', 'unregistered', 'sam_entity', 'ok', 0, 'nothing to resolve — every hub entity has a sam_entity row');
    exit(0);
}
printf("Resolving %d UEIs against the SAM Entity API...\n", count($ueis));

/** GET one page; Http.php already scrubs api_key from error text. $reqs counts the actual call
 *  (SAM sends no usage header, so counting our own requests is the honest quota signal). */
function ent_page(string $base, string $key, array $ueis, bool $registered, int $page, int &$reqs): array
{
    $q = http_build_query([
        'ueiSAM' => implode('~', $ueis),
        'size'   => 10, 'page' => $page,
    ] + ($registered ? [] : ['samRegistered' => 'No']));
    [, , $d] = Http::getJson("$base/entity-information/v3/entities?api_key=$key&$q",
                             ['Accept: application/json']);
    $reqs++;
    return is_array($d) ? $d : [];
}

/** All entityData records for a UEI batch in one mode (paged, paced). */
function ent_fetch(string $base, string $key, array $ueis, bool $registered, int $paceUs, int &$reqs): array
{
    $out = [];
    for ($page = 0; ; $page++) {
        $d = ent_page($base, $key, $ueis, $registered, $page, $reqs);
        foreach (($d['entityData'] ?? []) as $rec) $out[] = $rec;
        if (count($out) >= (int) ($d['totalRecords'] ?? 0) || !($d['entityData'] ?? [])) break;
        usleep($paceUs);
    }
    return $out;
}

/** Shared field mapping from a v3 entityData record. */
function ent_row(array $rec): array
{
    $reg  = $rec['entityRegistration'] ?? [];
    $core = $rec['coreData'] ?? [];
    $addr = $core['physicalAddress'] ?? [];
    $info = $core['entityInformation'] ?? [];
    return [
        'uei'                          => strtoupper((string) $reg['ueiSAM']),
        'legal_business_name'          => cl($reg['legalBusinessName'] ?? null, 255),
        'dba_name'                     => cl($reg['dbaName'] ?? null, 255),
        'cage_code'                    => cl($reg['cageCode'] ?? null, 10),
        'registration_status'          => cl($reg['registrationStatus'] ?? null, 20),
        'uei_status'                   => cl($reg['ueiStatus'] ?? null, 20),
        'uei_creation_date'            => dd($reg['ueiCreationDate'] ?? null),
        'registration_date'            => dd($reg['registrationDate'] ?? null),
        'registration_expiration_date' => dd($reg['registrationExpirationDate'] ?? $reg['expirationDate'] ?? null),
        'last_update_date'             => dd($reg['lastUpdateDate'] ?? null),
        'activation_date'              => dd($reg['activationDate'] ?? null),
        'purpose_of_registration_code' => cl($reg['purposeOfRegistrationCode'] ?? null, 10),
        'purpose_of_registration_desc' => cl($reg['purposeOfRegistrationDesc'] ?? null, 100),
        'exclusion_status_flag'        => cl($reg['exclusionStatusFlag'] ?? null, 1),
        'physical_address_line1'       => cl($addr['addressLine1'] ?? null, 255),
        'physical_address_city'        => cl($addr['city'] ?? null, 100),
        'physical_address_state'       => cl($addr['stateOrProvinceCode'] ?? null, 10),
        'physical_address_zip'         => cl($addr['zipCode'] ?? null, 10),
        'physical_address_country'     => cl($addr['countryCode'] ?? null, 3),
        'congressional_district'       => cl($core['congressionalDistrict'] ?? null, 10),
        'entity_start_date'            => dd($info['entityStartDate'] ?? null),
        'fiscal_year_end_close_date'   => cl($info['fiscalYearEndCloseDate'] ?? null, 10),
        'last_synced'                  => gmdate('Y-m-d H:i:s'),
    ];
}

function unreg_log(PDO $pdo, int $rows, string $status, ?string $msg, string $start): void
{
    $pdo->prepare(
        "INSERT INTO sync_log (source, scope, table_name, rows_upserted, status, message, started_at, finished_at)
         VALUES ('sam', 'unregistered', 'sam_entity', :rows, :status, :msg, :start, UTC_TIMESTAMP())"
    )->execute([':rows' => $rows, ':status' => $status, ':msg' => $msg, ':start' => $start]);
}

/** Ping/reconnect: quota-wall sleeps can outlive wait_timeout (see sync_subawards.php). */
function ensure_db(PDO &$pdo, Upserter &$up): void
{
    try { $pdo->query('SELECT 1'); return; } catch (Throwable $e) { /* dead — reconnect */ }
    $pdo = Db::connect();
    $up  = new Upserter($pdo);
    if (!(int) $pdo->query("SELECT GET_LOCK('aero_sync_sam_unreg', 0)")->fetchColumn()) {
        fwrite(STDERR, "DB connection was lost and another run now holds the lock; exiting.\n");
        exit(1);
    }
}

// ---- batched resolve ---------------------------------------------------------
$start = gmdate('Y-m-d H:i:s');
$t0 = microtime(true);
$nAssigned = 0; $nRegistered = 0; $nNotFound = 0; $nReq = 0;   // $nReq = actual SAM Entity API calls (quota truth)
$logId = RunLog::start($pdo, 'sam', 'unregistered', 'sam_entity');   // 'running' row; finalized below

foreach (array_chunk($ueis, 50) as $bi => $batch) {
    // quota walls (HTTP 429 past Http.php's short retries) ride out the daily reset,
    // same pattern as sync_subawards.php: 2x15min for transient throttling, then hourly
    for ($attempt = 1; ; $attempt++) {
        try {
            // 1) never-registered UEIs ("ID Assigned"): the expected majority
            $found = [];
            foreach (ent_fetch($base, $key, $batch, false, $paceUs, $nReq) as $rec) {
                $row = ent_row($rec);
                $found[$row['uei']] = true;
                $row['registration_status'] ??= 'ID Assigned';
                ensure_db($pdo, $up);
                $up->insert('sam_entity', $row);
                $nAssigned++;
            }
            usleep($paceUs);

            // 2) leftovers may hold a real registration the extract missed (entity
            //    registered since the extract was cut, or added to the hub after the
            //    extract load — the loader only keeps UEIs already in the hub)
            $left = array_values(array_diff(array_map('strtoupper', $batch), array_keys($found)));
            if ($left) {
                foreach (ent_fetch($base, $key, $left, true, $paceUs, $nReq) as $rec) {
                    $row = ent_row($rec);
                    $found[$row['uei']] = true;
                    ensure_db($pdo, $up);
                    $up->insert('sam_entity', $row);
                    $pdo->prepare("UPDATE entity SET has_sam=1, last_seen=UTC_TIMESTAMP() WHERE uei=:uei")
                        ->execute([':uei' => $row['uei']]);
                    $nRegistered++;
                }
            }

            // 3) the rest: SAM's public API has nothing for these UEIs
            foreach ($left as $u) {
                if (isset($found[$u])) continue;
                ensure_db($pdo, $up);
                $up->insert('sam_entity', [
                    'uei'                 => $u,
                    'registration_status' => 'Not Found',
                    'last_synced'         => gmdate('Y-m-d H:i:s'),
                ]);
                $nNotFound++;
            }
            break;
        } catch (Throwable $e) {
            if (strpos($e->getMessage(), 'HTTP 429') !== false && $attempt <= 30) {
                $nap = $attempt <= 2 ? 900 : 3600;
                printf("  batch %d quota wall (attempt %d) - sleeping %d min... [%s]\n",
                       $bi + 1, $attempt, intdiv($nap, 60), gmdate('H:i'));
                RunLog::defer($pdo, $logId, $nap + 120, "quota wall — napping " . intdiv($nap, 60) . "min");
                // Record the hard cap for Data Status: SAM's Entity API resets at midnight UTC.
                ensure_db($pdo, $up);
                QuotaObs::limitHit($pdo, 'sam', 1000, null, 'SAM Entity API daily limit reached');
                sleep($nap);
                continue;
            }
            ensure_db($pdo, $up);   // the failure may BE the dead connection; log on a live one
            RunLog::finish($pdo, $logId, 'sam', 'unregistered', 'sam_entity', 'error',
                           $nAssigned + $nRegistered + $nNotFound, substr($e->getMessage(), 0, 500), $nReq);
            fwrite(STDERR, "  batch " . ($bi + 1) . " FAILED: " . substr($e->getMessage(), 0, 200)
                         . "\n  Re-run to resume (resolved UEIs are skipped).\n");
            exit(1);
        }
    }
    if (($bi + 1) % 10 === 0 || ($bi + 1) * 50 >= count($ueis)) {
        printf("  %5d / %d UEIs  (ID Assigned %d, registered %d, not found %d, %.1f min)\n",
               min(($bi + 1) * 50, count($ueis)), count($ueis),
               $nAssigned, $nRegistered, $nNotFound, (microtime(true) - $t0) / 60);
        RunLog::progress($pdo, $logId, $nAssigned + $nRegistered + $nNotFound,
                         min(($bi + 1) * 50, count($ueis)) . '/' . count($ueis) . " UEIs · ID Assigned $nAssigned · registered $nRegistered · not found $nNotFound", $nReq);
    }
    usleep($paceUs);
}

ensure_db($pdo, $up);
RunLog::finish($pdo, $logId, 'sam', 'unregistered', 'sam_entity', 'ok',
               $nAssigned + $nRegistered + $nNotFound,
               "ID Assigned $nAssigned, registered $nRegistered, not found $nNotFound", $nReq);
printf("Done. %d UEIs resolved in %.1f min: %d ID Assigned, %d registered (extract stragglers), %d not found.\n",
       $nAssigned + $nRegistered + $nNotFound, (microtime(true) - $t0) / 60,
       $nAssigned, $nRegistered, $nNotFound);
