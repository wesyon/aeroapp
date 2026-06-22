<?php
declare(strict_types=1);

/**
 * AERO — reference dimensions seeder (CLI).
 * Pulls the small GSA/SAM reference catalogs straight from their APIs:
 *   - assistance_listing  (Assistance Listings API, ~2,848 active programs)
 *   - federal_agency      (Federal Hierarchy API, ~907 orgs)
 *
 * Usage:
 *   php sync_reference.php                 # both
 *   php sync_reference.php --only=agencies # agencies | listings
 */

$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
require $root . '/lib/Http.php';
Env::load(dirname($root, 2) . '/.env');   // above web root (prod; first-set wins)
Env::load(dirname($root) . '/.env');      // repo root (local dev)

function s($v): ?string { if ($v === null) return null; $v = trim((string) $v); return $v === '' ? null : $v; }
function dt($v): ?string { $v = s($v); if ($v === null) return null; $t = strtotime($v); return $t ? date('Y-m-d H:i:s', $t) : null; }

/** GET JSON with retry/backoff. $accept matters: the Assistance Listings API
 *  only serves application/hal+json (application/json -> HTTP 406). */
function api_get(string $url, string $accept = 'application/json', int $tries = 5): array
{
    $delay = 2;
    for ($i = 1; $i <= $tries; $i++) {
        try {
            [, , $d] = Http::getJson($url, ['Accept: ' . $accept]);
            return is_array($d) ? $d : [];
        } catch (Throwable $e) {
            if ($i === $tries) throw $e;
            fwrite(STDERR, "    retry $i: " . substr($e->getMessage(), 0, 70) . "\n");
            sleep($delay);
            $delay = min($delay * 2, 30);
        }
    }
    return [];
}

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$only = $args['only'] ?? null;

$pdo  = Db::connect();
$up   = new Upserter($pdo);
$key  = Env::require('SAM_API_KEY');
$base = rtrim(Env::get('SAM_BASE_URL', 'https://api.sam.gov'), '/');

// --- federal_agency (Federal Hierarchy) -------------------------------------
if ($only !== 'listings') {
    $off = 0; $lim = 200; $n = 0;
    while (true) {
        $d = api_get("$base/prod/federalorganizations/v1/orgs?api_key=$key&limit=$lim&offset=$off");
        $list = $d['orglist'] ?? [];
        if (!$list) break;
        $pdo->beginTransaction();
        foreach ($list as $o) {
            if (!isset($o['fhorgid'])) continue;
            $up->insert('federal_agency', [
                'fhorgid'           => (int) $o['fhorgid'],
                'fhorgname'         => s($o['fhorgname'] ?? null),
                'fhorgtype'         => s($o['fhorgtype'] ?? null),
                'status'            => s($o['status'] ?? null),
                'parent_orgid'      => isset($o['fhdeptindagencyorgid']) ? (int) $o['fhdeptindagencyorgid'] : null,
                'agency_org_name'   => s($o['fhagencyorgname'] ?? null),
                'agency_code'       => s($o['agencycode'] ?? null),
                'created_date'      => dt($o['createddate'] ?? null),
                'last_updated_date' => dt($o['lastupdateddate'] ?? null),
                'last_synced'       => gmdate('Y-m-d H:i:s'),
            ]);
            $n++;
        }
        $pdo->commit();
        $off += count($list);
        if (count($list) < $lim) break;
    }
    echo "  federal_agency      $n orgs\n";
}

// --- assistance_listing (Assistance Listings) -------------------------------
// BOTH statuses: the no-param default is Active-only, but FAC awards reference
// thousands of RETIRED programs (Provider Relief Fund, ESSER, FFEL...) whose
// catalog entries are Inactive — without them their dollars lose program names
// and drop out of the catalog-joined footprint breakdowns.
if ($only !== 'agencies') {
    $n = 0; $skip = 0;
    foreach (['Active', 'Inactive'] as $status) {
    $page = 1;
    while (true) {
        $d = api_get("$base/assistance-listings/v1/search?api_key=$key&pageSize=100&pageNumber=$page&status=$status", 'application/hal+json');
        $list = $d['assistanceListingsData'] ?? [];
        if (!$list) break;
        $pdo->beginTransaction();
        foreach ($list as $a) {
            try {
                $id = s($a['assistanceListingId'] ?? null);
                if ($id === null) { $skip++; continue; }
                $fo = $a['federalOrganization'] ?? [];
                $ov = $a['overview'] ?? [];
                $fi = $a['financialInformation'] ?? [];
                $up->insert('assistance_listing', [
                    'assistance_listing_id'          => $id,
                    'program_id'                     => s($a['programId'] ?? null),
                    'title'                          => s($a['title'] ?? null),
                    'status'                         => s($a['status'] ?? null),
                    'version'                        => s($a['version'] ?? null),
                    'published_date'                 => dt($a['publishedDate'] ?? null),
                    'popular_long_name'              => s($a['popularLongName'] ?? null),
                    'popular_short_name'             => s($a['popularShortName'] ?? null),
                    'department'                     => s($fo['department'] ?? null),
                    'department_code'                => s($fo['departmentCode'] ?? null),
                    'agency'                         => s($fo['agency'] ?? null),
                    'agency_code'                    => s($fo['agencyCode'] ?? null),
                    'program_web_page'               => s($a['programWebPage'] ?? null),
                    'objective'                      => s($ov['objective'] ?? null),
                    'assistance_listing_description' => s($ov['assistanceListingDescription'] ?? null),
                    'is_funded_current_fy'           => array_key_exists('isFundedCurrentFY', $fi) ? (int) (bool) $fi['isFundedCurrentFY'] : null,
                    'last_synced'                    => gmdate('Y-m-d H:i:s'),
                ]);
                $n++;
            } catch (Throwable $e) {
                $skip++;
            }
        }
        $pdo->commit();
        $tp = (int) ($d['totalPages'] ?? $page);
        if ($page >= $tp || count($list) < 100) break;
        $page++;
    }
    }
    echo "  assistance_listing  $n programs (active + inactive)" . ($skip ? " ($skip skipped: no ALN id)" : "") . "\n";
}

echo "Done.\n";
