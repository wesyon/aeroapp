<?php
declare(strict_types=1);
/**
 * Local admin console (Settings → Update Data).
 *   GET  /api/admin?action=status      — every data source: tables, row counts, last update
 *   POST /api/admin?action=run&source= — launch that source's sync script (localhost only)
 * Sync scripts are CLI; long ones run detached in the background and report via row counts
 * / sync_log on the next status poll.
 */

// Pipeline order: raw pulls → derived. Per source:
//   log      = sync_log source key (if the script logs)
//   ts       = [table, datetime column] fallback for "last updated"
//   coverage = [table, date column] to report the stored DATA's date range
//   group    = 'source' (pulled from a federal API) | 'derived' (computed locally)
//   origin   = plain-language provenance (where the data comes from)
//   authority= the statute/regulation behind it (informational)
//   cadence  = how often it refreshes (display)
//   fresh    = expected max age in days before "Aging" (freshness badge threshold)
$SOURCES = [
    ['key' => 'fac', 'label' => 'Federal Audit Clearinghouse', 'script' => 'sync_fac.php', 'log' => 'fac', 'chains' => true,
     'group' => 'source', 'origin' => 'fac.gov dissemination API + bulk CSV extracts', 'authority' => 'Single Audit Act (31 U.S.C. §7502) · 2 CFR 200 Subpart F', 'cadence' => 'Nightly (new + resubmitted reports)', 'fresh' => 2,
     'desc' => 'Single-audit submissions: general info, findings & text, federal awards, CAPs, pass-through, notes. Update pulls newly accepted reports, then re-extracts and rescores.',
     'tables' => ['fac_general', 'fac_findings', 'fac_findings_text', 'fac_federal_awards', 'fac_finding_awards',
                  'fac_corrective_action_plans', 'fac_passthrough', 'fac_notes_to_sefa', 'fac_additional_eins',
                  'fac_additional_ueis', 'fac_resubmission', 'fac_secondary_auditors', 'entity']],
    ['key' => 'sam', 'label' => 'SAM.gov entities & exclusions', 'script' => 'sync_sam.php', 'ts' => ['sam_entity', 'last_synced'],
     'group' => 'source', 'origin' => 'SAM.gov Entity & Exclusions public extracts', 'authority' => '2 CFR part 25 (UEI) · 2 CFR part 180 (debarment)', 'cadence' => 'Weekly', 'fresh' => 9,
     'desc' => 'Entity registration status, debarment/exclusion records, business types, NAICS.',
     'tables' => ['sam_entity', 'sam_exclusion', 'sam_business_type', 'sam_entity_naics']],
    ['key' => 'subawards', 'label' => 'Subaward passthrough (FSRS)', 'script' => 'build_subaward_edge.php', 'log' => 'subedge',
     'group' => 'source', 'origin' => 'SAM Subaward Reporting (FSRS) API', 'authority' => 'FFATA · 2 CFR part 170', 'cadence' => 'Detail mirrored locally; aggregate built + pushed', 'fresh' => 14,
     'desc' => 'Prime-to-sub pass-through flows powering the Passthrough tab. The full FSRS detail mirror (sam_assistance_subaward, ~2.8 GB) is too large for prod, so it lives only locally; the tab reads the pre-aggregated subaward_edge (one row per prime↔sub↔year) plus an entity-type lookup, built locally and shipped to prod. Freshness reflects the last build/push of the aggregate.',
     // subaward_edge first: it's the populated prod table. sam_assistance_subaward shows
     // its real count locally (the mirror) and 0 on prod (off-prod by design).
     'tables' => ['subaward_edge', 'subaward_entity_type', 'sam_assistance_subaward']],
    ['key' => 'usaspending', 'label' => 'USAspending awards', 'script' => 'sync_usa.php', 'ts' => ['usa_recipient', 'last_synced'],
     'group' => 'source', 'origin' => 'api.usaspending.gov (per-recipient)', 'authority' => 'DATA Act of 2014', 'cadence' => 'Nightly (findings + rollup members)', 'fresh' => 4,
     'desc' => 'Prime federal award obligations per recipient, fetched per-UEI and cached; transactions split by action date for fiscal-year accuracy.',
     'tables' => ['usa_award', 'usa_award_txn_month', 'usa_award_cfda', 'usa_recipient']],
    ['key' => 'reference', 'label' => 'Reference catalogs', 'script' => 'sync_reference.php', 'ts' => ['assistance_listing', 'last_synced'],
     'group' => 'source', 'origin' => 'SAM Assistance Listings + Federal Hierarchy APIs', 'authority' => 'OMB program catalog (CFDA/ALN)', 'cadence' => 'Weekly', 'fresh' => 9,
     'desc' => 'Assistance Listings (ALN/CFDA program catalog, active + inactive) and federal agency names.',
     'tables' => ['assistance_listing', 'federal_agency']],
    ['key' => 'extract', 'label' => 'Finding-text extract', 'script' => 'parse_findings.php', 'ts' => ['fac_finding_extract', 'parsed_at'],
     'group' => 'derived', 'origin' => 'Parsed from FAC finding narratives', 'authority' => 'GAGAS elements of a finding', 'cadence' => 'After each FAC update', 'fresh' => 2,
     'desc' => 'Unpacks finding text into GAGAS sections (criteria, condition, cause, effect…) and extracts the questioned-cost dollar amounts FAC does not expose.',
     'tables' => ['fac_finding_extract']],
    ['key' => 'score', 'label' => 'AERO risk scores', 'script' => 'compute_scores.php', 'log' => 'score', 'ts' => ['aero_score', 'computed_at'],
     'group' => 'derived', 'origin' => 'Computed from FAC data (7-component model)', 'authority' => '2 CFR part 200 component weights', 'cadence' => 'After each FAC update', 'fresh' => 2,
     'desc' => 'Recomputes the seven-component AERO risk score and tier for every recipient.',
     'tables' => ['aero_score']],
    ['key' => 'crosswalk', 'label' => 'State → UEI crosswalk', 'script' => 'seed_crosswalk.php', 'ts' => ['state_uei', 'updated_at'],
     'group' => 'derived', 'origin' => 'Name-matched from FAC, hand-correctable', 'authority' => null, 'cadence' => 'Weekly (fills gaps; preserves edits)', 'fresh' => 40,
     'desc' => 'Maps each state government to its UEI(s), powering the State Govt facet in Search. Auto-seeded by name; hand-edited rows are preserved.',
     'tables' => ['state_uei']],
];
$byKey = [];
foreach ($SOURCES as $s) $byKey[$s['key']] = $s;

$action = q_str('action') ?? 'status';
$isLocal = is_local_request();

// `status` is READ-ONLY (row counts, freshness, data coverage) and is served
// everywhere — in prod it's the public data-provenance / freshness view. The
// state-changing / cost-incurring actions stay local-only: `run` shells out to the
// CLI sync scripts, and `gaps` spends the FAC API key on every call.
if (in_array($action, ['run', 'gaps'], true) && !$isLocal) {
    json_out(['error' => 'This action is available only from the local AERO install.'], 403);
}

if ($action === 'run') {
    // State-changing (shells out to sync scripts, incl. the destructive full
    // re-sync): POST only, and when a browser supplies an Origin it must be local.
    // Blocks simple-request CSRF (a hostile page firing requests at 127.0.0.1) and
    // admin use through a tunnel; CLI/curl (no Origin header) still passes.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_out(['error' => 'POST required'], 405);
    }
    $reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($reqOrigin !== '' && !preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#i', $reqOrigin)) {
        json_out(['error' => 'cross-origin admin actions are not allowed'], 403);
    }
    $key = q_str('source');
    if ($key === null || !isset($byKey[$key])) json_out(['error' => 'unknown source'], 400);
    $mode = q_str('mode') ?? 'incremental';

    $php = getenv('PHP_CLI') ?: (defined('PHP_BINARY') ? PHP_BINARY : 'php');
    $syncDir = dirname(__DIR__) . '/sync/';
    $cacheDir = dirname(__DIR__) . '/cache';
    @mkdir($cacheDir, 0775, true);

    // Build the step chain. FAC defaults to a NON-destructive incremental pull
    // (--since the latest accepted date) then refreshes the derived tables; only
    // mode=full does the destructive whole-dataset delete-and-reload.
    $steps = [];
    if ($key === 'fac') {
        $year = q_str('year');
        if ($mode === 'full') {
            $steps[] = [$syncDir . 'sync_fac.php', ''];                          // destructive whole-dataset reload
        } elseif ($year !== null && preg_match('/^\d{4}$/', $year)) {
            $steps[] = [$syncDir . 'sync_fac.php', '--years=' . $year . ' --safe']; // reload one year to fill a gap
        } else {
            // 7-day overlap (matching the README cron guidance): FAC can disseminate a
            // report days after its fac_accepted_date, and a sync in between would put
            // that date behind MAX() forever — the overlap re-pulls the window instead.
            $since = $pdo->query("SELECT DATE_SUB(MAX(fac_accepted_date), INTERVAL 7 DAY) FROM fac_general")->fetchColumn() ?: '2022-01-01';
            $steps[] = [$syncDir . 'sync_fac.php', '--since=' . $since . ' --safe']; // incremental, recoverable
        }
        $steps[] = [$syncDir . 'parse_findings.php', ''];        // re-extract QC / GAGAS sections
        $steps[] = [$syncDir . 'build_entity_directory.php', ''];// refresh search/profile backbone (score-independent)
        $steps[] = [$syncDir . 'compute_scores.php', ''];        // rescore from refreshed data
    } elseif ($key === 'subawards') {
        // Coverage-aware: small gap -> 7-day-overlap delta (picks up new AND deleted
        // reports). Large gap (unfinished/stalled backfill) -> the RESUMABLE windowed
        // backfill from the coverage edge; a single --since delta spanning months
        // isn't resumable and would burn its quota from scratch on every retry.
        // Empty table -> full backfill. Rescore after (subawards drive has_passthrough).
        $maxSub = $pdo->query("SELECT MAX(submitted_date) FROM sam_assistance_subaward")->fetchColumn();
        $gapDays = $maxSub ? (int) floor((time() - strtotime((string) $maxSub)) / 86400) : null;
        $edge = $maxSub ? date('Y-m-d', strtotime($maxSub . ' -7 days')) : null;
        $steps[] = [$syncDir . 'sync_subawards.php',
                    $maxSub === false || $maxSub === null ? ''
                        : ($gapDays <= 14 ? '--since=' . $edge : '--from=' . $edge)];
        $steps[] = [$syncDir . 'compute_scores.php', ''];
    } elseif ($key === 'sam') {
        // extract reload first, then resolve the UEIs the extract can't see
        // (never-registered "ID Assigned" + not-found) via the live entity API
        $steps[] = [$syncDir . 'sync_sam.php', ''];
        $steps[] = [$syncDir . 'sync_sam_unregistered.php', '--recheck=35'];
        $steps[] = [$syncDir . 'sync_sam_detail.php', '--limit=8000'];   // structure/business types/NAICS (live API, ~800 req chunk under SAM's 1,000/day cap)
    } else {
        if (!is_file($syncDir . $byKey[$key]['script'])) json_out(['error' => 'sync script not found'], 500);
        $steps[] = [$syncDir . $byKey[$key]['script'], ''];
    }

    // Detached chain via a .bat (reliable Windows quoting); each step gated on the
    // previous (&&) so we don't rescore a failed pull. Rollup caches (dashboard,
    // evaluation, repeat, recipient filter options) busted after, matching nightly_sync.ps1.
    $win = fn ($p) => str_replace('/', '\\', $p);
    $cmds = [];
    foreach ($steps as [$script, $args]) {
        $cmds[] = '"' . $win($php) . '" "' . $win($script) . '"' . ($args !== '' ? ' ' . $args : '');
    }
    $logFile = $cacheDir . '/sync_' . $key . '.log';
    $statusFile = $cacheDir . '/run_' . $key . '.status';
    $bat = $cacheDir . '/run_' . $key . '.bat';
    // The bat marks running → done/failed so the console can show live completion.
    file_put_contents(
        $bat,
        "@echo off\r\n"
        . 'echo running>"' . $win($statusFile) . '"' . "\r\n"
        . '( ' . implode(' && ', $cmds) . ' ) > "' . $win($logFile) . '" 2>&1' . "\r\n"
        . 'set RC=%errorlevel%' . "\r\n"
        . 'del /Q "' . $win($cacheDir) . '\dashboard*.json" "' . $win($cacheDir) . '\evaluation*.json" "'
        .            $win($cacheDir) . '\repeat_*.json" "' . $win($cacheDir) . '\recipient_opts.json" >nul 2>&1' . "\r\n"
        . 'if "%RC%"=="0" (echo done>"' . $win($statusFile) . '") else (echo failed>"' . $win($statusFile) . '")' . "\r\n"
    );
    @file_put_contents($statusFile, 'running');   // immediate, before the bat spins up
    @pclose(@popen('cmd /c start "" /B cmd /c ' . escapeshellarg($win($bat)), 'r'));

    json_out([
        'started' => true, 'source' => $key,
        'mode' => $key === 'fac' ? ($mode === 'full' ? 'full' : 'incremental') : 'run',
        'steps' => array_map(fn ($s) => basename($s[0]) . ($s[1] ? ' ' . $s[1] : ''), $steps),
    ]);
}

if ($action === 'gaps') {
    // Compare local report counts per audit year against FAC's authoritative totals,
    // so any missing reports (from a failed sync, partial pull, etc.) are visible.
    require_once dirname(__DIR__) . '/lib/Http.php';
    require_once dirname(__DIR__) . '/lib/FacClient.php';
    $fac = new FacClient(Env::require('FAC_BASE_URL'), Env::require('FAC_API_KEY'));
    $local = [];
    foreach ($pdo->query("SELECT audit_year y, COUNT(*) n FROM fac_general WHERE audit_year IS NOT NULL GROUP BY audit_year") as $r) {
        $local[(int) $r['y']] = (int) $r['n'];
    }
    $years = range(2022, (int) date('Y'));
    foreach (array_keys($local) as $y) if (!in_array($y, $years, true)) $years[] = $y;
    sort($years);
    $rows = [];
    foreach ($years as $y) {
        $loc = $local[$y] ?? 0;
        $remote = null;
        try { $remote = $fac->count('general', 'audit_year=eq.' . $y); } catch (Throwable $e) { /* leave null */ }
        $rows[] = ['year' => $y, 'local' => $loc, 'fac' => $remote, 'missing' => $remote !== null ? max(0, $remote - $loc) : null];
    }
    json_out(['years' => $rows, 'checked_at' => date('c')]);
}

// Row count for a table. information_schema's TABLE_ROWS is an InnoDB estimate that drifts
// badly after a bulk load (e.g. a freshly backfilled usa_award_txn_month read ~10k vs the real
// ~800k) and self-corrects only on ANALYZE. The old fallback caught only an exact 0, so a
// stale-but-nonzero estimate slipped through. Instead: take an exact COUNT(*) for small/moderate
// tables — cheap (~0.1s) and always correct — and trust the estimate only for the multi-million-
// row tables where COUNT would be slow. The estimate just gates the choice.
$rowCount = function (string $t, int $estimate) use ($pdo): int {
    if ($estimate >= 1500000) return $estimate;   // genuinely huge: COUNT too slow, estimate is fine
    try { return (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); } catch (Throwable $e) { return $estimate; }
};

// --- data dictionary (read-only reference: every table, its source, size, count, desc) ---
if ($action === 'dictionary') {
    // Plain-language description of every table. Order here = display order.
    $DICT = [
        // Federal Audit Clearinghouse
        'fac_general' => 'One row per Single Audit submission — the audit\'s header: who was audited, their EIN/UEI, fiscal-year end, total federal dollars expended, audit type, and acceptance date.',
        'fac_federal_awards' => 'Every federal grant/award on an audit\'s Schedule of Expenditures of Federal Awards (SEFA): the program (ALN), funding agency, and amount expended. The most detailed FAC table, and the basis for HHS scoping.',
        'fac_findings' => 'Individual audit findings — each control or compliance problem the auditor identified, with its type flags (material weakness, questioned costs, repeat, modified opinion, etc.).',
        'fac_findings_text' => 'The full narrative text of each finding (criteria, condition, cause, effect, recommendation) as written by the auditor.',
        'fac_finding_awards' => 'Links each finding to the specific federal award(s) it relates to.',
        'fac_corrective_action_plans' => 'The auditee\'s corrective action plan for each finding — what they say they will do to fix it.',
        'fac_passthrough' => 'Pass-through relationships reported in the audit — which entity passed federal money down to the auditee (the auditee acting as a subrecipient).',
        'fac_notes_to_sefa' => 'Free-text notes accompanying the Schedule of Federal Awards (accounting policies, de minimis indirect-cost rate, etc.).',
        'fac_additional_eins' => 'Secondary employer ID numbers (EINs) associated with an audit, beyond the primary one.',
        'fac_additional_ueis' => 'Secondary UEIs covered by an audit — e.g. component agencies included under a statewide single audit (used to recognize entities audited under a parent).',
        'fac_resubmission' => 'Tracks resubmitted / superseded audit reports so findings from an old version are not double-counted.',
        'fac_secondary_auditors' => 'Additional audit firms that worked on a report beyond the primary auditor.',
        'entity' => 'The master recipient list — one row per UEI with name, state, and entity type. The universal join key tying FAC, SAM, and USAspending together.',
        // SAM.gov
        'sam_entity' => 'SAM.gov registration records — each entity\'s registration status (Active / expired), key dates, address, and structure.',
        'sam_exclusion' => 'The federal debarment / exclusion list — entities barred from receiving federal awards (FAR 9.4 / 2 CFR part 180).',
        'sam_business_type' => 'Business-type classifications per entity from SAM (e.g. "U.S. State Government", "For Profit Organization") — backfilled from the live Entity API by sync_sam_detail.php (not in the monthly extract).',
        'sam_entity_naics' => 'NAICS industry codes per entity from SAM — backfilled from the live Entity API by sync_sam_detail.php (not in the monthly extract; governments have none).',
        // Subaward passthrough
        'subaward_edge' => 'Pre-aggregated subaward flows powering the Passthrough tab — one row per prime → subrecipient pair per year, with the subaward count and total dollars passed down.',
        'subaward_entity_type' => 'Classifies each subaward counterparty (Government / Higher Ed / Nonprofit / For-Profit) and whether it owes a Single Audit — drives the ≥ $1M-no-audit flag.',
        'sam_assistance_subaward' => 'The full FSRS subaward detail (every prime → sub pass-through record). Kept LOCALLY only — too large (~2.8 GB) for the production database; the aggregate above is shipped to prod instead. Reads 0 on prod by design.',
        // USAspending
        'usa_award' => 'Prime federal awards each recipient received, from USAspending — grants, direct payments, and loans.',
        'usa_award_txn_month' => 'Per-award monthly obligation amounts, split by each transaction\'s action date and program (ALN) — powers the action-date fiscal-year split on the USAspending tab, instead of lumping an award on its base-obligation date.',
        'usa_award_cfda' => 'The program (ALN / CFDA) breakdown for each USAspending award.',
        'usa_recipient' => 'Per-recipient USAspending sync record, including the last-refreshed timestamp that paces the nightly crawl.',
        // Reference
        'assistance_listing' => 'The federal program catalog (ALN / CFDA) — program numbers, titles, and the agency that owns each. Used to turn ALN codes into program names.',
        'federal_agency' => 'Federal agency and sub-agency names and codes.',
        // Derived
        'fac_finding_extract' => 'Structured fields parsed out of the finding narrative text — most importantly the questioned-cost dollar amounts FAC does not expose directly.',
        'aero_score' => 'The computed AERO risk score and tier per recipient, plus denormalized profile columns (state, type, audit history) used across the app. Score/tier display is currently paused, but this table remains the search/profile backbone.',
        'state_uei' => 'Maps each state and territory government to its UEI(s) — powers the State Govt and US Territories facets in Search. Hand-correctable.',
        // Operational
        'sync_log' => 'Audit trail of every sync run — what ran, when, status, and rows updated. Powers the Data Status freshness view.',
        'schema_migrations' => 'Ledger of database migrations that have been applied (one row per migration file).',
    ];
    // table -> owning source metadata (label / origin / cadence) from $SOURCES
    $tableSrc = [];
    foreach ($SOURCES as $s) {
        foreach ($s['tables'] as $t) {
            $tableSrc[$t] = ['source' => $s['label'], 'origin' => $s['origin'] ?? null, 'cadence' => $s['cadence'] ?? null];
        }
    }
    // sizes (data + index, MB) + estimate row counts for every table, one query.
    // Explicit aliases (the connection returns information_schema columns upper-cased).
    $meta = [];
    foreach ($pdo->query("SELECT TABLE_NAME AS tn, ROUND((DATA_LENGTH+INDEX_LENGTH)/1024/1024,1) AS mb, TABLE_ROWS AS tr
                          FROM information_schema.tables WHERE table_schema = DATABASE()") as $r) {
        $meta[$r['tn']] = ['mb' => (float) $r['mb'], 'rows' => (int) $r['tr']];
    }
    $tables = [];
    foreach ($DICT as $t => $desc) {
        $src = $tableSrc[$t] ?? ['source' => 'Operational', 'origin' => 'AERO internal', 'cadence' => 'Continuous'];
        $n = $rowCount($t, (int) ($meta[$t]['rows'] ?? 0));
        $tables[] = [
            'table' => $t, 'description' => $desc,
            'source' => $src['source'], 'origin' => $src['origin'], 'cadence' => $src['cadence'],
            'size_mb' => $meta[$t]['mb'] ?? 0, 'rows' => $n,
        ];
    }
    // Database size cap (Hostinger quota) for the "X MB of Y MB" header. Configurable via
    // DB_CAP_MB; defaults to the prod 3 GB quota, null elsewhere (local has no real cap).
    $dbCap = Env::get('DB_CAP_MB') !== null
        ? (int) Env::get('DB_CAP_MB')
        : (strtolower((string) Env::get('APP_ENV', '')) === 'prod' ? 3072 : null);
    json_out(['tables' => $tables, 'db_cap_mb' => $dbCap, 'generated_at' => date('c')]);
}

// --- status ---
$rows = [];
foreach ($pdo->query("SELECT TABLE_NAME AS tn, TABLE_ROWS AS tr FROM information_schema.tables WHERE table_schema = DATABASE()") as $r) {
    $rows[$r['tn']] = (int) $r['tr'];
}
$log = [];
foreach ($pdo->query("SELECT s.source, s.status, s.finished_at, s.rows_upserted, s.scope
                      FROM sync_log s JOIN (SELECT source, MAX(id) mid FROM sync_log GROUP BY source) m ON m.mid = s.id") as $r) {
    $log[$r['source']] = $r;
}

// Tables whose MOST RECENT sync skipped rows (status <> ok). A later clean run clears it.
$partials = [];
foreach ($pdo->query("SELECT s.source, s.table_name, s.scope, s.status, s.message, s.finished_at
                      FROM sync_log s
                      JOIN (SELECT source, table_name, MAX(id) mid FROM sync_log GROUP BY source, table_name) m ON m.mid = s.id
                      WHERE s.status <> 'ok' AND s.status IS NOT NULL AND s.source <> 'nightly'
                      ORDER BY s.finished_at DESC") as $r) {
    $partials[] = [
        'source' => $r['source'], 'table' => $r['table_name'], 'scope' => $r['scope'],
        'status' => $r['status'], 'message' => $r['message'], 'finished_at' => $r['finished_at'],
    ];
}

$out = [];
foreach ($SOURCES as $s) {
    // Exact COUNT(*) for small/moderate tables, estimate for the huge ones (see $rowCount).
    $tables = array_map(function ($t) use ($rows, $rowCount) {
        return ['name' => $t, 'rows' => $rowCount($t, (int) ($rows[$t] ?? 0))];
    }, $s['tables']);
    $updated = null; $status = null; $rowsUp = null;
    if (isset($s['log'], $log[$s['log']])) {
        $updated = $log[$s['log']]['finished_at'];
        $status = $log[$s['log']]['status'];
        $rowsUp = $log[$s['log']]['rows_upserted'] !== null ? (int) $log[$s['log']]['rows_upserted'] : null;
    }
    if (isset($s['ts'])) {
        try {
            $m = $pdo->query("SELECT MAX(`{$s['ts'][1]}`) FROM `{$s['ts'][0]}`")->fetchColumn();
            if ($m && (!$updated || $m > $updated)) $updated = $m;
        } catch (Throwable $e) { /* column/table absent */ }
    }
    // data coverage (date range actually stored) — both MIN and MAX are loose index
    // scans on the configured date column, so this costs nothing per poll
    $coverage = null;
    if (isset($s['coverage'])) {
        try {
            [$ct, $cc] = $s['coverage'];
            $r = $pdo->query("SELECT MIN(`$cc`) cmin, MAX(`$cc`) cmax FROM `$ct`")->fetch();
            if ($r && $r['cmax'] !== null) {
                $coverage = ['from' => $r['cmin'], 'to' => $r['cmax'],
                             'behind_days' => max(0, (int) floor((time() - strtotime($r['cmax'])) / 86400))];
            }
        } catch (Throwable $e) { /* column/table absent */ }
    }
    // live job state from the run marker (running always; done/failed for ~15 min after)
    $job = null;
    $jf = dirname(__DIR__) . '/cache/run_' . $s['key'] . '.status';
    if (is_file($jf)) {
        $state = trim((string) file_get_contents($jf));
        if ($state === 'running' || (in_array($state, ['done', 'failed'], true) && (time() - filemtime($jf)) < 900)) {
            $job = ['state' => $state, 'at' => date('c', filemtime($jf))];
        }
        // a completed console run dates the source even when the script doesn't log / has
        // no timestamp column (gmdate: DB timestamps are UTC, so compare like-for-like)
        if ($state === 'done' && (!$updated || ($mt = gmdate('Y-m-d H:i:s', filemtime($jf))) > $updated)) {
            $updated = gmdate('Y-m-d H:i:s', filemtime($jf));
        }
    }
    $out[] = [
        'key' => $s['key'], 'label' => $s['label'], 'desc' => $s['desc'],
        'group' => $s['group'] ?? 'source',
        'origin' => $s['origin'] ?? null, 'authority' => $s['authority'] ?? null,
        'cadence' => $s['cadence'] ?? null, 'fresh_days' => $s['fresh'] ?? 7,
        'chained' => !empty($s['chains']), 'job' => $job,
        'command' => 'php api/sync/' . $s['script'],
        'tables' => $tables,
        'total_rows' => array_sum(array_column($tables, 'rows')),
        'last_updated' => $updated, 'status' => $status, 'rows_upserted' => $rowsUp,
        'coverage' => $coverage,
    ];
}

// Nightly run heartbeat (sync_log source 'nightly', written by api/sync/heartbeat.php at the
// end of each scheduled run). Surfaces a stalled/failed nightly prominently rather than
// letting the per-source freshness badges slowly age — the gap that hid a 3-day outage.
$nightly = null;
if (isset($log['nightly']) && $log['nightly']['finished_at']) {
    $fin  = $log['nightly']['finished_at'];                 // stored UTC
    $ageH = (time() - strtotime($fin . ' UTC')) / 3600;
    $nightly = [
        'status'      => $log['nightly']['status'],
        'finished_at' => $fin,
        'age_hours'   => round($ageH, 1),
        'stale'       => $log['nightly']['status'] !== 'ok' || $ageH > 36,   // last run failed, or a night was missed
    ];
}

json_out([
    'sources'      => $out,
    'partials'     => $partials,
    'nightly'      => $nightly,
    'can_run'      => $isLocal,
    'total_rows'   => array_sum(array_column($out, 'total_rows')),
    'generated_at' => date('c'),
]);
