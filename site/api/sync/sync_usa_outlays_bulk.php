<?php
declare(strict_types=1);

/**
 * AERO — USAspending OUTLAY sync via BULK File C download (CLI, LOCAL build tool).
 *
 * The per-award /funding/ crawl (sync_usa_outlays.php) is ~836k separate API calls and trips
 * USAspending's per-IP throttle. This does the same job in a HANDFUL of bulk downloads instead:
 * the Custom Account Data download (File C = "award_financial") — requested per (fiscal year,
 * QUARTER) — returns every award's gross_outlay_amount_FYB_to_period_end cumulative AS OF that
 * quarter's period end (P3=Dec, P6=Mar, P9=Jun, P12=Sep). Verified to match the /funding/ API to
 * the penny. It runs through files.usaspending.gov (a DIFFERENT path than the throttled API).
 *
 * Two phases:
 *   1. STAGE  — for each (fy, quarter): download the File C zip, stream the Assistance CSV, keep only
 *               OUR awards (award_unique_key ∈ usa_award), sum the CPE per award, upsert into the
 *               usa_award_outlay_cpe staging table. Resumable: a done (fy,quarter) is skipped.
 *   2. DIFFERENCE (--difference) — per award, per federal FY, difference consecutive quarters into the
 *               quarter's outlay, place it at the quarter-END month (Dec/Mar/Jun/Sep) in
 *               usa_award_outlay_month, and set usa_award.outlay_synced. The existing route/UI then
 *               roll those into entity FYs unchanged. EXACT for the 93% of entities on quarter-
 *               boundary fiscal-year-ends; the ~7% with odd year-ends get a quarterly approximation.
 *
 * Usage:
 *   php sync_usa_outlays_bulk.php --fy=2024 --quarter=3 --agency=68     # one agency (test; 68=HHS)
 *   php sync_usa_outlays_bulk.php --fy=2024 --quarter=3                 # ALL agencies (real backfill)
 *   php sync_usa_outlays_bulk.php --matrix --fys=2021-2026             # every quarter × FY, all agencies
 *   php sync_usa_outlays_bulk.php --difference                         # phase 2: staging → outlay_month
 */

ini_set('memory_limit', '2048M');

$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
require $root . '/lib/Http.php';
require $root . '/lib/RunLog.php';
Env::load(dirname($root, 2) . '/.env');
Env::load(dirname($root) . '/.env');

const DL_BASE = 'https://api.usaspending.gov';
const FILES_BASE = 'https://files.usaspending.gov/generated_downloads/';
const Q_PERIOD = [1 => 3, 2 => 6, 3 => 9, 4 => 12];   // quarter → fiscal period end

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$pdo = Db::connect();

// staging table (cumulative-period-end per award, per federal FY, per quarter period)
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS usa_award_outlay_cpe (
       award_id VARCHAR(64)   NOT NULL,
       fy       SMALLINT       NOT NULL,   -- federal fiscal year
       period   TINYINT        NOT NULL,   -- reporting period (3,6,9,12 = quarter ends)
       cpe      DECIMAL(18,2)  NOT NULL,   -- gross outlay cumulative FYB→period end, summed over the award's accounts
       PRIMARY KEY (award_id, fy, period)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// download log — makes the ~900-download matrix RESUMABLE: a completed (agency, fy, period) is skipped.
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS usa_outlay_dl_log (
       agency  VARCHAR(8) NOT NULL,
       fy      SMALLINT   NOT NULL,
       period  TINYINT    NOT NULL,
       staged  INT        NOT NULL DEFAULT 0,
       done_at DATETIME   NOT NULL,
       PRIMARY KEY (agency, fy, period)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

if (isset($args['difference'])) { difference_and_populate($pdo); exit(0); }

$scratch = sys_get_temp_dir() . '/aero_filec';
@mkdir($scratch, 0777, true);

// The awards we keep (match File C's award_unique_key). Loaded once, reused across downloads.
function load_our_awards(PDO $pdo): array
{
    $set = [];
    $st = $pdo->query("SELECT award_id FROM usa_award WHERE category <> 'loan'");
    while (($id = $st->fetchColumn()) !== false) $set[$id] = true;
    return $set;
}

/** The download agency ids our awards come from, ordered by our award count (biggest first). */
function agency_download_ids(PDO $pdo): array
{
    $ours = [];
    foreach ($pdo->query(
        "SELECT awarding_toptier_agency n, COUNT(*) c FROM usa_award
         WHERE category <> 'loan' AND total_outlay IS NOT NULL AND total_outlay <> 0 AND awarding_toptier_agency IS NOT NULL
         GROUP BY awarding_toptier_agency ORDER BY c DESC") as $r) {
        $ours[$r['n']] = (int) $r['c'];
    }
    [, , $d] = Http::postJson(DL_BASE . '/api/v2/bulk_download/list_agencies/', ['type' => 'account_agencies'], [], 3);
    $name2id = [];
    $scan = function ($o) use (&$scan, &$name2id) {
        if (!is_array($o)) return;
        if (isset($o['toptier_agency_id'], $o['name'])) $name2id[$o['name']] = (string) $o['toptier_agency_id'];
        foreach ($o as $v) $scan($v);
    };
    $scan($d);
    $out = [];   // [download_id => name], our-award-count order
    foreach ($ours as $name => $c) if (isset($name2id[$name])) $out[$name2id[$name]] = $name;
    return $out;
}

/** Kick off a File C download job and return its file_name, or throw. */
function filec_start(int $fy, int $period, ?string $agency): string
{
    $filters = ['fy' => (string) $fy, 'period' => $period, 'submission_types' => ['award_financial']];
    if ($agency !== null && $agency !== 'all') $filters['agency'] = $agency;
    [, , $d] = Http::postJson(DL_BASE . '/api/v2/download/accounts/', [
        'account_level' => 'federal_account', 'filters' => $filters,
    ], [], 3);
    $fn = $d['file_name'] ?? null;
    if (!$fn) throw new RuntimeException('download start failed: ' . json_encode($d));
    return $fn;
}

/** Poll until the job is finished, then download the zip to $dest (with retries + validation). */
function filec_wait_download(string $fn, string $dest, int $maxWaitS = 2400): void
{
    $statusUrl = DL_BASE . '/api/v2/download/status?file_name=' . urlencode($fn);
    $deadline = time() + $maxWaitS;
    $finished = false;
    while (time() < $deadline) {
        [, , $d] = Http::getJson($statusUrl, [], 3);
        $st = $d['status'] ?? '';
        if ($st === 'finished') { $finished = true; break; }
        if ($st === 'failed') throw new RuntimeException("download job failed: $fn");
        sleep(8);   // tail slices generate in ~1-5 min; 20s polling wasted ~10s/slice on average
    }
    if (!$finished) throw new RuntimeException("download job never finished within {$maxWaitS}s: $fn");

    // Big files over a possibly-flaky connection: retry the transfer, resuming from bytes already on
    // disk (CURLOPT_RESUME_FROM), and validate we got a real zip (starts with the PK magic bytes).
    $url = FILES_BASE . $fn;
    // Each call downloads a FRESHLY-generated file (new file_name). A $dest left over from a prior
    // run/crash is a DIFFERENT (usually larger) file, so resuming it returns HTTP 416 and — since the
    // old code only cleared a partial on 200 — the retries wedged in an endless 416 loop. Start clean
    // here; the loop below still resumes within THIS call for genuine mid-transfer connection drops.
    @unlink($dest);
    for ($attempt = 1; $attempt <= 80; $attempt++) {   // big files over a flaky conn need many resume hops
        $have = is_file($dest) ? filesize($dest) : 0;
        $fp = fopen($dest, $have > 0 ? 'ab' : 'wb');
        if (!$fp) throw new RuntimeException("cannot open $dest");
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 3600,
            CURLOPT_CONNECTTIMEOUT => 30, CURLOPT_USERAGENT => 'PostmanRuntime/7.39.0',
            CURLOPT_RESUME_FROM => $have, CURLOPT_LOW_SPEED_LIMIT => 1024, CURLOPT_LOW_SPEED_TIME => 120,
        ]);
        $ok    = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fp);
        // success = curl ok, http 2xx/206, file present and a real zip (PK\x03\x04). A fully-downloaded
        // valid zip is good at ANY size: empty/tiny agency-periods (no File C data) come back as small
        // ~KB zips, which the old >100KB floor wrongly rejected -> endless retry wedge. Keep only a
        // trivial-fragment floor; the parse step (zip:// stream) is the real validator for corruption.
        clearstatcache();
        $size = is_file($dest) ? filesize($dest) : 0;
        $magic = '';
        if ($size > 4) { $r = fopen($dest, 'rb'); $magic = (string) fread($r, 2); fclose($r); }
        if ($ok && $code < 400 && $size > 1000 && $magic === 'PK') return;
        fwrite(STDERR, "  download attempt $attempt failed (errno=$errno code=$code size=$size err=" . substr($err, 0, 60) . "); retrying\n");
        // if the server can't resume (fresh 200, or 416 = our partial doesn't match this file), start clean
        if (($code === 200 || $code === 416) && $have > 0) @unlink($dest);
        sleep(5 * $attempt);
    }
    throw new RuntimeException("zip download failed after retries for $fn");
}

/**
 * Stream the Assistance CSV(s) inside $zip, summing gross_outlay CPE per award for the target period,
 * keeping only awards in $ours. Returns [award_id => cpe].
 */
function parse_cpe(string $zip, int $fy, int $period, array $ours): array
{
    $target = sprintf('FY%dP%02d', $fy, $period);
    $za = new ZipArchive();
    if ($za->open($zip) !== true) throw new RuntimeException("cannot open zip $zip");
    $entries = [];
    for ($i = 0; $i < $za->numFiles; $i++) {
        $name = $za->getNameIndex($i);
        if (stripos($name, 'Assistance') !== false && str_ends_with(strtolower($name), '.csv')) $entries[] = $name;
    }
    $za->close();

    $cpe = [];
    foreach ($entries as $entry) {
        $fh = fopen('zip://' . $zip . '#' . $entry, 'r');
        if (!$fh) continue;
        $header = fgetcsv($fh);
        if (!$header) { fclose($fh); continue; }
        $col = array_flip($header);
        $iKey = $col['award_unique_key'] ?? null;
        $iOut = $col['gross_outlay_amount_FYB_to_period_end'] ?? null;
        $iPer = $col['submission_period'] ?? null;
        if ($iKey === null || $iOut === null || $iPer === null) { fclose($fh); continue; }
        while (($row = fgetcsv($fh)) !== false) {
            if (($row[$iPer] ?? '') !== $target) continue;         // only the quarter's period end
            $k = $row[$iKey] ?? '';
            if ($k === '' || !isset($ours[$k])) continue;          // only our awards
            $v = $row[$iOut] ?? '';
            if ($v === '' || !is_numeric($v)) continue;
            $cpe[$k] = ($cpe[$k] ?? 0) + (float) $v;
        }
        fclose($fh);
    }
    return $cpe;
}

/** Upsert a [award_id => cpe] map into the staging table for (fy, period). */
function stage_cpe(PDO $pdo, int $fy, int $period, array $cpe): int
{
    $rows = 0;
    $flat = [];
    foreach ($cpe as $aid => $v) $flat[] = [$aid, $fy, $period, round($v, 2)];
    for ($off = 0; $off < count($flat); $off += 1000) {
        $chunk = array_slice($flat, $off, 1000);
        $ph = implode(',', array_fill(0, count($chunk), '(?,?,?,?)'));
        $vals = [];
        foreach ($chunk as $r) array_push($vals, $r[0], $r[1], $r[2], $r[3]);
        $pdo->prepare("INSERT INTO usa_award_outlay_cpe (award_id, fy, period, cpe) VALUES $ph
                       ON DUPLICATE KEY UPDATE cpe = VALUES(cpe)")->execute($vals);
        $rows += count($chunk);
    }
    return $rows;
}

/** Process one (fy, period[, agency]) download end to end. Resumable: skips if already logged done. */
function run_one(PDO $pdo, string $scratch, array $ours, int $fy, int $period, ?string $agency, ?string $preFn = null): void
{
    $agKey = ($agency === null || $agency === 'all') ? 'all' : $agency;
    $tag = sprintf('FY%dP%02d-a%s', $fy, $period, $agKey);
    $chk = $pdo->prepare("SELECT 1 FROM usa_outlay_dl_log WHERE agency = ? AND fy = ? AND period = ?");
    $chk->execute([$agKey, $fy, $period]);
    if ($chk->fetchColumn()) { echo "[$tag] already done, skip\n"; return; }

    echo "[$tag] starting…\n";
    // $preFn = a generation job submitted ahead by the --prefetch pipeline (see --allagencies):
    // their queue worked on this file while we processed the previous slice.
    $fn = $preFn ?? filec_start($fy, $period, $agency);
    $zip = "$scratch/$tag.zip";
    filec_wait_download($fn, $zip, 1200);   // 20 min: big agencies generate in <10min; a stuck job shouldn't stall longer
    printf("[$tag] downloaded (%.0f MB)\n", filesize($zip) / 1048576);
    $cpe = parse_cpe($zip, $fy, $period, $ours);
    $n = stage_cpe($pdo, $fy, $period, $cpe);
    @unlink($zip);
    $pdo->prepare("INSERT INTO usa_outlay_dl_log (agency, fy, period, staged, done_at)
                   VALUES (?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE staged = VALUES(staged), done_at = UTC_TIMESTAMP()")
        ->execute([$agKey, $fy, $period, $n]);
    printf("[$tag] staged %d of our awards (period P%02d)\n", $n, $period);
}

/** Phase 2: difference staged quarterly CPEs → quarterly deltas at quarter-end months. */
function difference_and_populate(PDO $pdo): void
{
    echo "differencing staged CPEs → usa_award_outlay_month…\n";
    // calendar month for a federal reporting period: P2→Nov, P3→Dec, P4→Jan … P12→Sep (P<=3 = prev cal yr).
    // (P2 is the first monthly submission and bundles Oct+Nov; only an Oct-31 FYE would be split by that.)
    $ymOf = function (int $fy, int $period): string {
        $m = (($period + 8) % 12) + 1;
        $y = $period <= 3 ? $fy - 1 : $fy;
        return sprintf('%04d-%02d-01', $y, $m);
    };
    // Delete scoped to the federal FYs being rebuilt (Oct..Sep window), NOT the whole award:
    // awards can carry API-sourced months (sync_usa_outlays.php per-award top-ups) for years the
    // bulk staging never captured — e.g. the staging-order race where an award was crawled after
    // its agency's FY2021-25 slices staged. A whole-award DELETE here would silently destroy them.
    $del  = $pdo->prepare("DELETE FROM usa_award_outlay_month WHERE award_id = ? AND ym >= ? AND ym < ?");
    $mark = $pdo->prepare("UPDATE usa_award SET outlay_synced = UTC_TIMESTAMP() WHERE award_id = ?");
    $ins  = $pdo->prepare("INSERT INTO usa_award_outlay_month (award_id, ym, outlay) VALUES (?,?,?)
                           ON DUPLICATE KEY UPDATE outlay = VALUES(outlay)");

    // staging can hold award_ids that have since churned OUT of usa_award (the nightly refresh
    // ran many times over the multi-day matrix build) — those would violate the FK; skip them.
    $valid = [];
    foreach ($pdo->query("SELECT award_id FROM usa_award") as $r) $valid[$r['award_id']] = true;
    $skipped = 0;

    // stream staging ordered so each award's (fy, period) rows arrive together.
    // (PDO buffers the full result client-side, so the same connection is free for the
    // batched write transactions below.)
    $q = $pdo->query("SELECT award_id, fy, period, cpe FROM usa_award_outlay_cpe ORDER BY award_id, fy, period");
    $curAward = null; $byFy = [];   // fy => [period => cpe]
    $awards = 0; $rows = 0;
    // Batch commits: autocommit costs ~11 fsyncs per award (DELETE + ~9 INSERTs + UPDATE),
    // which measured ~23 awards/s on the contended local disk — a 9-hour pass. One commit
    // per 1,000 awards puts the same work in the low tens of minutes.
    $BATCH = 1000;
    $pdo->beginTransaction();
    $flush = function (string $aid, array $byFy) use ($ymOf, $del, $ins, $mark, &$rows) {
        foreach (array_keys($byFy) as $fy) {
            $del->execute([$aid, sprintf('%04d-10-01', $fy - 1), sprintf('%04d-10-01', $fy)]);
        }
        foreach ($byFy as $fy => $periods) {
            ksort($periods);
            $prev = 0.0;
            foreach ($periods as $p => $cpe) {
                $delta = round($cpe - $prev, 2);
                $prev = $cpe;
                if ($delta != 0.0) { $ins->execute([$aid, $ymOf($fy, $p), $delta]); $rows++; }
            }
        }
        $mark->execute([$aid]);
    };
    foreach ($q as $r) {
        if ($curAward !== null && $r['award_id'] !== $curAward) {
            $flush($curAward, $byFy); $awards++; $byFy = [];
            if ($awards % $BATCH === 0) { $pdo->commit(); $pdo->beginTransaction(); }
            if ($awards % 25000 === 0) printf("  %d awards, %d month-rows…\n", $awards, $rows);
        }
        if (!isset($valid[$r['award_id']])) { $curAward = null; $byFy = []; $skipped++; continue; }
        $curAward = $r['award_id'];
        $byFy[(int) $r['fy']][(int) $r['period']] = (float) $r['cpe'];
    }
    if ($curAward !== null) { $flush($curAward, $byFy); $awards++; }
    $pdo->commit();
    printf("Done. %d awards → %d outlay month-rows (%d staged rows skipped: award no longer in usa_award).\n", $awards, $rows, $skipped);
}

// ---- main ----
$ours = load_our_awards($pdo);
echo 'loaded ' . number_format(count($ours)) . " of our award_ids\n";

$agency = isset($args['agency']) ? (string) $args['agency'] : null;   // null/'all' = every agency
// which reporting periods to pull. --period=N (one month) | --periods=3,6,9,12 (a set) | --quarter=Q |
// default = ALL months P12..P2 (exact monthly outlays for any fiscal-year-end).
$periods = isset($args['period'])  ? [(int) $args['period']]
         : (isset($args['periods']) ? array_values(array_filter(array_map('intval', explode(',', (string) $args['periods'])), fn ($p) => $p >= 2 && $p <= 12))
         : (isset($args['quarter']) ? [Q_PERIOD[(int) $args['quarter']]] : range(12, 2)));

if (isset($args['allagencies'])) {
    // The full per-agency matrix: every agency our awards touch × reporting period × FY. Sequential
    // (gentle, no throttle) and resumable via usa_outlay_dl_log; a single failed slice is logged/skipped.
    // Default periods = every MONTH (P12..P2) → exact monthly outlays for ANY fiscal-year-end.
    //
    // --prefetch=N (default 0 = fully sequential): submit the next N slices' GENERATION jobs while
    // the current slice waits/downloads/parses, so USAspending's queue works ahead instead of idling
    // between slices. Still ONE download stream (the throttle lesson of 7/2 and 7/8 — parallel
    // REQUEST streams get connection-reset); depth stays small so generated files never expire.
    $agencies = agency_download_ids($pdo);
    $range = explode('-', (string) ($args['fys'] ?? '2021-2025'));
    $fa = (int) $range[0]; $fb = (int) ($range[1] ?? $range[0]);
    $prefetch = max(0, (int) ($args['prefetch'] ?? 0));
    echo count($agencies) . ' agencies × ' . count($periods) . ' periods × ' . ($fb - $fa + 1) . " FYs"
       . ($prefetch ? " · prefetch depth $prefetch" : '') . "\n";

    if ($prefetch > 0) {
        // plan = the not-yet-done slices, in the same order the sequential loop would visit them
        $chk = $pdo->prepare("SELECT 1 FROM usa_outlay_dl_log WHERE agency = ? AND fy = ? AND period = ?");
        $plan = [];
        foreach (array_keys($agencies) as $aid) {
            for ($fy = $fb; $fy >= $fa; $fy--) {
                foreach ($periods as $p) {
                    $chk->execute([(string) $aid, $fy, $p]);
                    if (!$chk->fetchColumn()) $plan[] = [(string) $aid, $fy, $p];
                }
            }
        }
        $total = count($plan);
        echo "$total slices to do\n";
        $inflight = [];   // FIFO of [file_name, aid, fy, period] with submitted generation jobs
        $i = 0; $n = 0; $t0 = time(); $consecFail = 0;
        while ($i < $total || $inflight) {
            while (count($inflight) < 1 + $prefetch && $i < $total) {
                [$aid, $fy, $p] = $plan[$i];
                try {
                    $inflight[] = [filec_start($fy, $p, $aid), $aid, $fy, $p];
                    $i++; $consecFail = 0;
                    printf("[FY%dP%02d-a%s] job submitted (%d in flight)\n", $fy, $p, $aid, count($inflight));
                    usleep(750000);   // pace submits — a rapid burst of requests draws connection resets
                } catch (Throwable $e) {
                    // submit failed (usually a connection reset): back off and RETRY the same slice —
                    // don't burn plan entries in a cascade. After 10 straight failures, skip one and
                    // keep going (a later sweep pass retries anything skipped).
                    fwrite(STDERR, "  [a$aid FY$fy P$p] SUBMIT ERROR: " . substr($e->getMessage(), 0, 140) . "\n");
                    if (++$consecFail >= 10) { $i++; $consecFail = 0; }
                    sleep(min(60, 5 * $consecFail + 5));
                    break;   // process any inflight work before trying to submit again
                }
            }
            if (!$inflight) { if ($i >= $total) break; continue; }   // nothing in flight yet — keep retrying submits
            [$fn, $aid, $fy, $p] = array_shift($inflight);
            $n++;
            try { run_one($pdo, $scratch, $ours, $fy, $p, $aid, $fn); }
            catch (Throwable $e) { fwrite(STDERR, "  [a$aid FY$fy P$p] ERROR: " . substr($e->getMessage(), 0, 140) . "\n"); }
            if ($n % 20 === 0) printf("=== %d/%d slices (%.0f min elapsed) ===\n", $n, $total, (time() - $t0) / 60);
        }
        echo "matrix complete. run with --difference to populate usa_award_outlay_month.\n";
        exit(0);
    }

    $total = count($agencies) * ($fb - $fa + 1) * count($periods); $n = 0; $t0 = time();
    foreach (array_keys($agencies) as $aid) {
        for ($fy = $fb; $fy >= $fa; $fy--) {                 // recent FYs first
            foreach ($periods as $p) {                       // latest period (Sep) first
                $n++;
                try { run_one($pdo, $scratch, $ours, $fy, $p, (string) $aid); }
                catch (Throwable $e) { fwrite(STDERR, "  [a$aid FY$fy P$p] ERROR: " . substr($e->getMessage(), 0, 140) . "\n"); }
                if ($n % 20 === 0) printf("=== %d/%d slices (%.0f min elapsed) ===\n", $n, $total, (time() - $t0) / 60);
            }
        }
    }
    echo "matrix complete. run with --difference to populate usa_award_outlay_month.\n";
    exit(0);
}

if (isset($args['matrix'])) {
    [$fa, $fb] = array_map('intval', explode('-', (string) ($args['fys'] ?? '2021-2025')) + [1 => 2025]);
    // Same per-slice error tolerance as --allagencies: one dropped connection (the download
    // service sheds load under pressure) must skip the slice, not kill the whole worker.
    for ($fy = $fa; $fy <= $fb; $fy++) foreach ($periods as $p) {
        try { run_one($pdo, $scratch, $ours, $fy, $p, $agency); }
        catch (Throwable $e) { fwrite(STDERR, "  [a$agency FY$fy P$p] ERROR: " . substr($e->getMessage(), 0, 140) . "\n"); }
    }
} elseif (isset($args['fy'])) {
    $fy = (int) $args['fy'];
    foreach ($periods as $p) run_one($pdo, $scratch, $ours, $fy, $p, $agency);
} else {
    fwrite(STDERR, "need --fy=YYYY [--period=N | --quarter=N] [--agency=ID|all], or --allagencies, or --difference\n");
    exit(1);
}
echo "staging complete. run with --difference to populate usa_award_outlay_month.\n";
