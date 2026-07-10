<?php
declare(strict_types=1);

/**
 * AERO — SAM entity DETAIL enrichment (CLI). https://open.gsa.gov/api/entity-api/
 *
 * The SAM Entity monthly extract (sync_sam.php) carries the registration core fields but
 * NOT the entity structure, business types, or NAICS — those live only in the live Entity
 * Management API's coreData / assertions sections. This backfills them for registered hub
 * entities so the grantee "Entity Info" tab (which already renders all three) is populated
 * instead of blank:
 *   sam_entity.entity_structure   <- coreData.generalInformation.entityStructureDesc
 *   sam_business_type             <- coreData.businessTypes.businessTypeList (+ SBA list)
 *   sam_entity_naics              <- assertions.goodsAndServices.naicsList
 *
 * Resumable (selects registered entities whose entity_structure is still NULL), batched
 * ~50/request, paced, with the same quota-wall ride-out + reconnect as sync_sam_unregistered.
 *
 * Usage:
 *   php sync_sam_detail.php                # backfill all registered entities missing detail
 *   php sync_sam_detail.php --limit=2000   # cap this run (nightly chunk)
 *   php sync_sam_detail.php --type=state   # prioritize a hub entity_type slice (comma-separated ok)
 *   php sync_sam_detail.php --uei=XXXXXXXXXXXX
 */

$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
require $root . '/lib/Http.php';
require $root . '/lib/RunLog.php';
require $root . '/lib/QuotaObs.php';
Env::load(dirname($root, 2) . '/.env');
Env::load(dirname($root) . '/.env');

function dstr($v, int $n = 255): ?string { if ($v === null) return null; $v = trim((string) $v); return $v === '' ? null : mb_substr($v, 0, $n); }

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}

$pdo = Db::connect();
if (!(int) $pdo->query("SELECT GET_LOCK('aero_sam_detail', 0)")->fetchColumn()) {
    fwrite(STDERR, "Another sync_sam_detail.php run is already active; exiting.\n");
    exit(1);
}
$key    = Env::require('SAM_API_KEY');
$base   = rtrim(Env::get('SAM_BASE_URL', 'https://api.sam.gov'), '/');
$paceUs = (int) (max(0.0, (float) ($args['pace'] ?? 4.2)) * 1e6);
$limit  = isset($args['limit']) ? max(1, (int) $args['limit']) : null;

// registered hub entities still missing detail (resumable: a filled entity_structure drops out)
if (isset($args['uei']) && preg_match('/^[A-Za-z0-9]{12}$/', (string) $args['uei'])) {
    $ueis = [strtoupper((string) $args['uei'])];
} else {
    // Optional --type scopes the backfill to entity-hub entity_type(s) so a slice can go
    // first (e.g. --type=state, or --type=state,local). Resumable within the slice too.
    $join = $typeSql = ''; $params = [];
    if (!empty($args['type']) && is_string($args['type'])) {
        $types = array_values(array_filter(array_map('trim', explode(',', $args['type']))));
        if ($types) {
            $join    = " JOIN entity e ON e.uei = s.uei";
            $typeSql = " AND e.entity_type IN (" . implode(',', array_fill(0, count($types), '?')) . ")";
            $params  = $types;
        }
    }
    $st = $pdo->prepare(
        "SELECT s.uei FROM sam_entity s$join
         WHERE s.entity_structure IS NULL
           AND COALESCE(s.registration_status,'') NOT IN ('ID Assigned','Not Found')$typeSql
         ORDER BY s.uei"
    );
    $st->execute($params);
    $ueis = $st->fetchAll(PDO::FETCH_COLUMN);
}
if ($limit !== null) $ueis = array_slice($ueis, 0, $limit);
if (!$ueis) {
    echo "Nothing to enrich: every registered entity already has SAM detail.\n";
    // Log a clean no-op so a fully-caught-up night keeps the Data Status timestamp fresh
    // (rather than reading "stale" because there was simply nothing to do).
    RunLog::finish($pdo, null, 'sam', 'detail', 'sam_entity', 'ok', 0, 'nothing to enrich — all registered entities already have detail');
    exit(0);
}
printf("Enriching %d entities with SAM detail (structure / business types / NAICS)...\n", count($ueis));

/** All entityData records for a UEI batch, with the detail sections, paged + paced.
 *  $reqs is incremented per HTTP call so we can report the ACTUAL SAM Entity API request
 *  count (SAM sends no usage header — counting our own requests is the honest quota signal). */
function detail_fetch(string $base, string $key, array $ueis, int $paceUs, int &$reqs): array
{
    $out = [];
    for ($page = 0; ; $page++) {
        $q = http_build_query([
            'ueiSAM'          => implode('~', $ueis),
            'includeSections' => 'entityRegistration,coreData,assertions',
            'size'            => 10, 'page' => $page,
        ]);
        [, , $d] = Http::getJson("$base/entity-information/v3/entities?api_key=$key&$q", ['Accept: application/json']);
        $reqs++;
        foreach (($d['entityData'] ?? []) as $rec) $out[] = $rec;
        if (count($out) >= (int) ($d['totalRecords'] ?? 0) || !($d['entityData'] ?? [])) break;
        usleep($paceUs);
    }
    return $out;
}

// prepared statements, rebuildable after a reconnect (quota-wall sleeps can outlive wait_timeout)
$prep = fn (PDO $p) => [
    'es'    => $p->prepare("UPDATE sam_entity SET entity_structure = ?, last_synced = UTC_TIMESTAMP() WHERE uei = ?"),
    'delBt' => $p->prepare("DELETE FROM sam_business_type WHERE uei = ?"),
    'insBt' => $p->prepare("INSERT INTO sam_business_type (uei, type_code, type_desc) VALUES (?,?,?)
                            ON DUPLICATE KEY UPDATE type_desc = VALUES(type_desc)"),
    'delNz' => $p->prepare("DELETE FROM sam_entity_naics WHERE uei = ?"),
    'insNz' => $p->prepare("INSERT INTO sam_entity_naics (uei, naics_code, naics_description, is_primary) VALUES (?,?,?,?)
                            ON DUPLICATE KEY UPDATE naics_description = VALUES(naics_description), is_primary = VALUES(is_primary)"),
];
$st = $prep($pdo);
$ensure = function () use (&$pdo, &$st, $prep) {
    try { $pdo->query('SELECT 1'); return; } catch (Throwable $e) { /* dead — reconnect */ }
    $pdo = Db::connect();
    $pdo->query("SELECT GET_LOCK('aero_sam_detail', 0)");
    $st = $prep($pdo);
};

$start = gmdate('Y-m-d H:i:s'); $t0 = microtime(true);
$nEnt = 0; $nBt = 0; $nNz = 0; $nReq = 0;   // $nReq = actual SAM Entity API calls (quota truth)
$logId = RunLog::start($pdo, 'sam', 'detail', 'sam_entity');   // 'running' row; finalized below

foreach (array_chunk($ueis, 50) as $bi => $batch) {
    for ($attempt = 1; ; $attempt++) {
        try {
            $recs = detail_fetch($base, $key, $batch, $paceUs, $nReq);
            $ensure();
            foreach ($recs as $rec) {
                $uei = strtoupper((string) ($rec['entityRegistration']['ueiSAM'] ?? ''));
                if ($uei === '') continue;
                $core = $rec['coreData'] ?? [];

                $st['es']->execute([dstr($core['generalInformation']['entityStructureDesc'] ?? null, 50), $uei]);

                $types = [];
                foreach (($core['businessTypes']['businessTypeList'] ?? []) as $b) {
                    if (($c = dstr($b['businessTypeCode'] ?? null, 10)) !== null) $types[$c] = dstr($b['businessTypeDesc'] ?? null, 255);
                }
                foreach (($core['businessTypes']['sbaBusinessTypeList'] ?? []) as $b) {
                    if (($c = dstr($b['sbaBusinessTypeCode'] ?? null, 10)) !== null) $types[$c] = dstr($b['sbaBusinessTypeDesc'] ?? null, 255);
                }
                $st['delBt']->execute([$uei]);
                foreach ($types as $c => $desc) { $st['insBt']->execute([$uei, $c, $desc]); $nBt++; }

                $gs   = $rec['assertions']['goodsAndServices'] ?? [];
                $prim = dstr($gs['primaryNaics'] ?? null, 6);
                $st['delNz']->execute([$uei]);
                $seen = [];
                foreach (($gs['naicsList'] ?? []) as $z) {
                    $c = dstr($z['naicsCode'] ?? null, 6);
                    if ($c === null || isset($seen[$c])) continue;
                    $seen[$c] = true;
                    $st['insNz']->execute([$uei, $c, dstr($z['naicsDescription'] ?? null, 255), ($prim !== null && $c === $prim) ? 1 : 0]);
                    $nNz++;
                }
                $nEnt++;
            }
            break;
        } catch (Throwable $e) {
            if (strpos($e->getMessage(), 'HTTP 429') !== false && $attempt <= 30) {
                $nap = $attempt <= 2 ? 900 : 3600;
                printf("  batch %d quota wall (attempt %d) - sleeping %d min... [%s]\n", $bi + 1, $attempt, intdiv($nap, 60), gmdate('H:i'));
                RunLog::defer($pdo, $logId, $nap + 120, "quota wall — napping " . intdiv($nap, 60) . "min (at $nEnt)");
                // Record the hard cap for Data Status: SAM's Entity API resets at midnight UTC.
                $ensure();
                QuotaObs::limitHit($pdo, 'sam', 1000, null, 'SAM Entity API daily limit reached');
                sleep($nap);
                $ensure();
                continue;
            }
            $ensure();
            RunLog::finish($pdo, $logId, 'sam', 'detail', 'sam_entity', 'error', $nEnt, substr($e->getMessage(), 0, 500), $nReq);
            fwrite(STDERR, "  batch " . ($bi + 1) . " FAILED: " . substr($e->getMessage(), 0, 200) . "\n  Re-run to resume.\n");
            exit(1);
        }
    }
    if (($bi + 1) % 10 === 0 || ($bi + 1) * 50 >= count($ueis)) {
        printf("  %d / %d entities  (%d business types, %d NAICS, %.1f min)\n",
               min(($bi + 1) * 50, count($ueis)), count($ueis), $nBt, $nNz, (microtime(true) - $t0) / 60);
        RunLog::progress($pdo, $logId, $nEnt, min(($bi + 1) * 50, count($ueis)) . '/' . count($ueis) . " entities · $nBt business types · $nNz NAICS", $nReq);
    }
    usleep($paceUs);
}

$ensure();
RunLog::finish($pdo, $logId, 'sam', 'detail', 'sam_entity', 'ok', $nEnt, "detail for $nEnt entities ($nBt business types, $nNz NAICS)", $nReq);
printf("Done. %d entities enriched (%d business types, %d NAICS) in %.1f min.\n", $nEnt, $nBt, $nNz, (microtime(true) - $t0) / 60);
