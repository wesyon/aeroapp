<?php
declare(strict_types=1);

/**
 * AERO — SAM Assistance Subaward (FSRS) sync (CLI).
 * https://open.gsa.gov/api/assistance-subaward-reporting-api/
 *
 * The API has NO prime/sub UEI filter, so this is a date-windowed bulk mirror
 * into sam_assistance_subaward (a global-universe table with soft UEI links,
 * like sam_exclusion): walk fromDate/toDate windows page by page and join
 * locally. Idempotent via the uq_asub_report unique key (subawardReportId);
 * resumable per window via sync_log (a window logged 'ok' is skipped on re-run).
 *
 * Quota: the SAM key allows ~1,000 requests/hour; --pace (seconds between
 * requests, default 3.7 ≈ 970/hr) keeps a full FY2022+ backfill (~2.6M records,
 * ~2,600 requests) inside it in one ~3h run. Http.php retries 429s regardless.
 *
 * Usage:
 *   php sync_subawards.php                          # backfill 2021-10-01..today (monthly windows)
 *   php sync_subawards.php --from=2024-01-01 --to=2024-03-31
 *   php sync_subawards.php --since=2026-06-03       # nightly delta: upserts published,
 *                                                   #   removes deleted (overlap a few days —
 *                                                   #   the fromDate field semantics are undocumented)
 */

$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
require $root . '/lib/Http.php';
Env::load(dirname($root, 2) . '/.env');   // above web root (prod; first-set wins)
Env::load(dirname($root) . '/.env');      // repo root (local dev)

function s($v): ?string { if ($v === null) return null; $v = trim((string) $v); return $v === '' ? null : $v; }
/** Clip to a column's VARCHAR width — filers cram arbitrary text into short fields. */
function cl($v, int $n): ?string { $v = s($v); return $v === null ? $v : mb_substr($v, 0, $n); }
function num($v) { return is_numeric($v) ? $v + 0 : null; }
function uei12($v): ?string { $v = s($v); return ($v !== null && preg_match('/^[A-Za-z0-9]{12}$/', $v)) ? $v : null; }
function dd($v): ?string { $v = s($v); if ($v === null) return null; $t = strtotime($v); return $t ? date('Y-m-d', $t) : null; }

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}

$pdo  = Db::connect();
// 4.2s ≈ 860 req/hr: headroom under the key's 1,000/hr for the OTHER consumers of
// the same key (prod nightly delta, reference syncs, ad-hoc probes) — at 3.7s a
// backfill alone sat at ~970/hr and a concurrent delta tipped it into 429s.

// One run at a time: a duplicate launch (console button while a CLI backfill is
// already walking windows) would race the same windows and burn double quota.
// The lock is held by this DB connection and auto-releases when the process exits.
if (!(int) $pdo->query("SELECT GET_LOCK('aero_sync_subawards', 0)")->fetchColumn()) {
    fwrite(STDERR, "Another sync_subawards.php run is already active; exiting.\n");
    exit(1);
}

$up   = new Upserter($pdo);
$key  = Env::require('SAM_API_KEY');
$base = rtrim(Env::get('SAM_BASE_URL', 'https://api.sam.gov'), '/');
$paceUs = (int) (max(0.0, (float) ($args['pace'] ?? 4.2)) * 1e6);

/** GET one page; the api_key travels in the URL, so scrub it from any error text
 *  before it can reach stderr or sync_log (it would otherwise leak via messages). */
function sub_page(string $base, string $key, array $q): array
{
    $url = "$base/prod/assistance/v1/subawards/search?api_key=$key&" . http_build_query($q);
    try {
        [, , $d] = Http::getJson($url, ['Accept: application/json']);
        return is_array($d) ? $d : [];
    } catch (Throwable $e) {
        throw new RuntimeException(str_replace($key, '***', $e->getMessage()), 0);
    }
}

/** Map one API record to a sam_assistance_subaward row (NULL-safe throughout). */
function sub_row(array $r): array
{
    $al  = $r['assistanceListingNumber'][0] ?? [];
    $org = $r['organizationInfo'] ?? [];
    $pop = $r['placeOfPerformance'] ?? [];
    return [
        'subaward_report_id'        => cl($r['subawardReportId'] ?? null, 40),
        'subaward_report_number'    => cl($r['subawardReportNumber'] ?? null, 40),
        'subaward_number'           => cl($r['subAwardNumber'] ?? null, 60),
        'status'                    => cl($r['status'] ?? null, 20),
        'submitted_date'            => dd($r['submittedDate'] ?? null),
        'report_updated_date'       => dd($r['reportUpdatedDate'] ?? null),
        'prime_entity_uei'          => uei12($r['primeEntityUei'] ?? null),
        'prime_entity_name'         => cl($r['primeEntityName'] ?? null, 255),
        'prime_award_key'           => cl($r['primeAwardKey'] ?? null, 64),
        'fain'                      => cl($r['fain'] ?? null, 40),
        'aln'                       => cl($al['number'] ?? null, 24),
        'aln_title'                 => cl($al['title'] ?? null, 255),
        'agency_code'               => cl($r['agencyCode'] ?? null, 20),
        'funding_agency_code'       => cl($org['fundingAgency']['code'] ?? null, 20),
        'funding_agency_name'       => cl($org['fundingAgency']['name'] ?? null, 255),
        'awarding_agency_code'      => cl($org['awardingAgency']['code'] ?? null, 20),
        'awarding_agency_name'      => cl($org['awardingAgency']['name'] ?? null, 255),
        'sub_vendor_uei'            => uei12($r['subVendorUei'] ?? null),
        'sub_vendor_name'           => cl($r['subVendorName'] ?? null, 255),
        'sub_parent_uei'            => uei12($r['subParentUei'] ?? null),
        'sub_parent_name'           => cl($r['subParentName'] ?? null, 255),
        'subaward_amount'           => num($r['subAwardAmount'] ?? null),
        'subaward_date'             => dd($r['subAwardDate'] ?? null),
        'base_obligation_date'      => dd($r['baseObligationDate'] ?? null),
        'total_fed_funding_amount'  => num($r['totalFedFundingAmount'] ?? null),
        'base_assistance_type_code' => cl($r['baseAssistanceTypeCode'] ?? null, 20),
        'base_assistance_type_desc' => cl($r['baseAssistanceTypeDesc'] ?? null, 100),
        'subaward_description'      => s($r['subawardDescription'] ?? null),
        'project_description'       => s($r['projectDescription'] ?? null),
        'pop_city'                  => cl($pop['city'] ?? null, 100),
        'pop_state'                 => cl($pop['state']['code'] ?? null, 60),
        'pop_zip'                   => cl($pop['zip'] ?? null, 12),
        'pop_congressional_district' => cl($pop['congressionalDistrict'] ?? null, 10),
        // SAM-exclusive detail (absent from USAspending's subaward surfaces):
        // business types + vendor country + exec comp, for the for-profit/foreign analysis
        'sub_business_types'        => isset($r['subBusinessType']) && $r['subBusinessType'] !== null
                                         ? json_encode($r['subBusinessType'], JSON_UNESCAPED_SLASHES) : null,
        'sub_vendor_country'        => cl($r['vendorPhysicalAddress']['country']['code'] ?? null, 3),
        'sub_top_pay_employees'     => isset($r['subTopPayEmployee']) && $r['subTopPayEmployee'] !== null
                                         ? json_encode($r['subTopPayEmployee'], JSON_UNESCAPED_SLASHES) : null,
        'last_synced'               => gmdate('Y-m-d H:i:s'),
    ];
}

/** Pull every page of one window+status; returns [rows upserted, deleted report ids]. */
function sub_window(PDO $pdo, Upserter $up, string $base, string $key, string $from, string $to,
                    string $status, int $paceUs): array
{
    $rows = 0;
    $deletedIds = [];
    $page = 0;
    while (true) {
        $d = sub_page($base, $key, [
            'fromDate' => $from, 'toDate' => $to, 'status' => $status,
            'pageSize' => 1000, 'pageNumber' => $page,
        ]);
        $list = $d['data'] ?? [];
        if (!$list) break;
        $pdo->beginTransaction();
        foreach ($list as $r) {
            $rid = s($r['subawardReportId'] ?? null);
            if ($rid === null) continue;            // unique key can't dedupe a NULL id
            if ($status === 'Deleted') { $deletedIds[] = $rid; continue; }
            $up->insert('sam_assistance_subaward', sub_row($r));
            $rows++;
        }
        $pdo->commit();
        $page++;
        if ($page >= (int) ($d['totalPages'] ?? 0) || count($list) < 1000) break;
        usleep($paceUs);
    }
    return [$rows, $deletedIds];
}

/**
 * Ping the connection; if it died, reconnect and re-take the run lock.
 * The quota-wall retry loop sleeps for hours making ZERO DB calls (walled requests
 * never reach a transaction), so even the 8h session wait_timeout can expire —
 * MySQL then kills the connection, which silently RELEASES the GET_LOCK and makes
 * the next window (and the error logger itself) die on "server has gone away".
 * Observed live 2026-06-11: a backfill zombied overnight exactly this way.
 * The Upserter must be rebuilt too — its cached prepared statements are bound to
 * the dead connection.
 */
function ensure_db(PDO &$pdo, Upserter &$up): void
{
    try {
        $pdo->query('SELECT 1');
        return;
    } catch (Throwable $e) {
        // dead — reconnect below
    }
    $pdo = Db::connect();
    $up  = new Upserter($pdo);
    if (!(int) $pdo->query("SELECT GET_LOCK('aero_sync_subawards', 0)")->fetchColumn()) {
        fwrite(STDERR, "DB connection was lost and another sync_subawards.php now holds the lock; exiting.\n");
        exit(1);
    }
}

function sub_log(PDO $pdo, string $scope, int $rows, string $status, ?string $msg, string $start): void
{
    $pdo->prepare(
        "INSERT INTO sync_log (source, scope, table_name, rows_upserted, status, message, started_at, finished_at)
         VALUES ('subawards', :scope, 'sam_assistance_subaward', :rows, :status, :msg, :start, UTC_TIMESTAMP())"
    )->execute([':scope' => $scope, ':rows' => $rows, ':status' => $status,
                ':msg' => $msg, ':start' => $start]);
}

// ---- delta mode (--since): published upserts + deleted removals -----------------
if (isset($args['since']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $args['since'])) {
    $since = (string) $args['since'];
    $today = gmdate('Y-m-d');
    $start = gmdate('Y-m-d H:i:s');
    echo "Delta sync: $since..$today (published + deleted)\n";
    [$rows, ]        = sub_window($pdo, $up, $base, $key, $since, $today, 'Published', $paceUs);
    [, $deletedIds]  = sub_window($pdo, $up, $base, $key, $since, $today, 'Deleted', $paceUs);
    $removed = 0;
    foreach (array_chunk($deletedIds, 500) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("DELETE FROM sam_assistance_subaward WHERE subaward_report_id IN ($in)");
        $st->execute($chunk);
        $removed += $st->rowCount();
    }
    sub_log($pdo, "since $since", $rows, 'ok', $removed ? "$removed deleted reports removed" : null, $start);
    printf("Done. %d upserted, %d removed (of %d deleted reports seen).\n", $rows, $removed, count($deletedIds));
    exit;
}

// ---- backfill mode: windowed walk, resumable via sync_log ----------------------
$from = new DateTimeImmutable((string) ($args['from'] ?? '2021-10-01'));   // FY2022+
$to   = new DateTimeImmutable((string) ($args['to'] ?? gmdate('Y-m-d')));
$winDays = max(1, (int) ($args['window'] ?? 31));

$done = [];   // window scopes already completed
foreach ($pdo->query("SELECT scope FROM sync_log WHERE source='subawards' AND status='ok'") as $r) {
    $done[$r['scope']] = true;
}

$total = 0;
$t0 = microtime(true);
for ($cur = $from; $cur <= $to; $cur = $wEnd->modify('+1 day')) {
    $wEnd = $cur->modify('+' . ($winDays - 1) . ' days');
    if ($wEnd > $to) $wEnd = $to;
    $scope = $cur->format('Y-m-d') . '..' . $wEnd->format('Y-m-d');
    if (isset($done[$scope])) { echo "  $scope already done, skipping\n"; continue; }
    $start = gmdate('Y-m-d H:i:s');
    // Quota walls (HTTP 429 past Http.php's short retries) are EXPECTED: this API's
    // quota is DAILY (~1,000 req/day observed — a 429 persisted across an hour of
    // 15-min retries, so it is not an hourly window). A full backfill needs several
    // daily windows, so ride them out: short sleeps first (transient throttling),
    // then hourly sleeps long enough to span the daily reset (~30h max).
    for ($attempt = 1; ; $attempt++) {
        try {
            ensure_db($pdo, $up);   // hours of quota-wall sleeps can outlive wait_timeout
            [$rows, ] = sub_window($pdo, $up, $base, $key, $cur->format('Y-m-d'), $wEnd->format('Y-m-d'), 'Published', $paceUs);
            sub_log($pdo, $scope, $rows, 'ok', null, $start);
            $total += $rows;
            printf("  %s  %7d rows  (%.1f min elapsed)\n", $scope, $rows, (microtime(true) - $t0) / 60);
            break;
        } catch (Throwable $e) {
            if (strpos($e->getMessage(), 'HTTP 429') !== false && $attempt <= 30) {
                $nap = $attempt <= 2 ? 900 : 3600;   // 2x15min, then hourly across the daily reset
                printf("  %s quota wall (attempt %d) - sleeping %d min... [%s]\n",
                       $scope, $attempt, intdiv($nap, 60), gmdate('H:i'));
                sleep($nap);
                continue;
            }
            ensure_db($pdo, $up);   // the failure may BE the dead connection; log on a live one
            sub_log($pdo, $scope, 0, 'error', substr($e->getMessage(), 0, 500), $start);
            fwrite(STDERR, "  $scope FAILED: " . substr($e->getMessage(), 0, 200) . "\n  Re-run to resume from this window.\n");
            exit(1);
        }
    }
    usleep($paceUs);
}
printf("Done. %d rows across all windows in %.1f min.\n", $total, (microtime(true) - $t0) / 60);
