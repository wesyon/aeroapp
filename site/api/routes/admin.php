<?php
declare(strict_types=1);
/**
 * Local admin console (Settings → Update Data).
 *   GET  /api/admin?action=status      — every data source: tables, row counts, last update
 *   POST /api/admin?action=run&source= — launch that source's sync script (localhost only)
 * Sync scripts are CLI; long ones run detached in the background and report via row counts
 * / sync_log on the next status poll.
 */

// Local-only console whose count/coverage queries compete with heavy backfills for the same
// DB — under a multi-worker download the cold-cache path can exceed PHP's default 30s limit,
// which killed the request mid-render (intermittent empty-body 500s on Data Status). Let it
// finish instead; the per-query caches below keep the warm path fast.
set_time_limit(180);

/**
 * Cache a sub-job's coverage {d,t} to a JSON file for $ttl seconds. These done/total queries each scan
 * ~1M rows and run for EVERY sub-job on every status poll — recomputing them live drags the endpoint
 * to 20s+ during a heavy backfill (when the DB is already saturated). A ~minute-stale progress bar is
 * fine, so recompute at most once per $ttl. Returns null on error / no data (bar just hides).
 */
function coverage_cached(PDO $pdo, string $key, string $sql, int $ttl = 60): ?array
{
    $file = dirname(__DIR__) . '/cache/cov_' . preg_replace('/[^A-Za-z0-9_]/', '_', $key) . '.json';
    if (is_file($file) && (time() - filemtime($file)) < $ttl) {
        $c = json_decode((string) file_get_contents($file), true);
        if (is_array($c) && isset($c['d'], $c['t'])) return $c;
    }
    try {
        $row = $pdo->query($sql)->fetch();
    } catch (\Throwable $e) {
        return null;
    }
    if (!$row || $row['t'] === null) return null;
    $res = ['d' => (int) $row['d'], 't' => (int) $row['t']];
    cache_put($file, $res);
    return $res;
}

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
                  'fac_additional_ueis', 'fac_resubmission', 'fac_secondary_auditors', 'entity'],
     // FAC's dissemination API is behind api.data.gov (X-Api-Key) → ~1,000 requests/HOUR. api.data.gov
     // returns X-RateLimit-Remaining on every response, so FacClient records the live count to
     // api_quota_obs → truth='header' (the indicator shows the API's own number, not an estimate).
     'quota' => ['limit' => 1000, 'window' => 'hour', 'per_req' => 500, 'truth' => 'header', 'label' => 'FAC API (api.data.gov)']],
    ['key' => 'sam', 'label' => 'SAM.gov entities & exclusions', 'script' => 'sync_sam.php', 'ts' => ['sam_entity', 'last_synced'],
     'group' => 'source', 'origin' => 'SAM.gov Entity & Exclusions public extracts', 'authority' => '2 CFR part 25 (UEI) · 2 CFR part 180 (debarment)', 'cadence' => 'Weekly extract · nightly enrichment', 'fresh' => 9,
     'desc' => 'Entity registration status, debarment/exclusion records, business types, NAICS.',
     'tables' => ['sam_entity', 'sam_exclusion', 'sam_business_type', 'sam_entity_naics'],
     // Sub-jobs that log under source 'sam' with their own scope, surfaced individually: the bulk
     // weekly extract keeps the card "fresh" (and the extract also CASCADE-wipes the detail tables
     // each run), so a per-UEI sub-job that silently stops dead is invisible on the source badge —
     // the gap that hid blank business types / NAICS / entity structure prod-wide until 2026-06-30.
     // coverage = [done, total] backfill progress (these are multi-week backfills, not one-shot
     // syncs — showing only the last run reads as "done" when it's 5% in); chunk = nightly rate,
     // for the est.-nights-remaining. unit for display.
     'subjobs' => [
         ['scope' => 'detail', 'label' => 'Detail enrichment (structure / business types / NAICS)', 'fresh' => 10,
          // Dynamic blurb: state the universe arithmetic with LIVE counts so the two bars'
          // denominators (all tracked vs. has-a-registration) read as nested scopes, not a gap.
          'blurb' => static function (PDO $pdo): string {
              $t = (int) $pdo->query('SELECT COUNT(*) FROM entity')->fetchColumn();
              $e = (int) $pdo->query("SELECT COUNT(*) FROM sam_entity WHERE COALESCE(registration_status,'') NOT IN ('ID Assigned','Not Found')")->fetchColumn();
              return number_format($t) . ' tracked entities − ' . number_format(max(0, $t - $e))
                   . ' never-registered / no-SAM-record (nothing to read) = ' . number_format($e)
                   . ' with registration contents to enrich. The lookup below resolves all ' . number_format($t) . '.';
          },
          'coverage' => "SELECT SUM(entity_structure IS NOT NULL) d, COUNT(*) t FROM sam_entity WHERE COALESCE(registration_status,'') NOT IN ('ID Assigned','Not Found')",
          'chunk' => 2500, 'unit' => 'entities'],
         ['scope' => 'unregistered', 'label' => 'Unregistered entities (SAM lookup)', 'fresh' => 40,
          'blurb' => "Looks up entities we track that aren't in SAM's registration file — never registered, not yet registered, or no SAM record found.",
          // What the lookups FOUND (the bar only says the work finished): registration-status mix
          // across every resolved entity. Labels mapped in SQL so the PHP below stays generic.
          'facts' => "SELECT CASE registration_status WHEN 'Inactive' THEN 'Lapsed' WHEN 'ID Assigned' THEN 'Never registered' WHEN 'Not Found' THEN 'No SAM record' ELSE registration_status END k, COUNT(*) n
                      FROM sam_entity WHERE registration_status IS NOT NULL GROUP BY k
                      ORDER BY FIELD(k, 'Active', 'Lapsed', 'Expired', 'Never registered', 'No SAM record'), n DESC",
          'facts_note' => 'An ACTIVE SAM registration is required to receive federal awards (2 CFR part 25) — lapsed, expired, or never-registered entities still receiving funds are an oversight signal.',
          // done = RESOLVED against SAM (a sam_entity row exists, whatever the answer). Counting
          // SUM(has_sam) here was wrong: the lookup deliberately leaves has_sam=0 on 'ID Assigned' /
          // 'Not Found' resolutions, so ~4.9k completed "you're not registered" answers read as
          // pending work forever and the bar could never reach 100%.
          'coverage' => "SELECT COUNT(s.uei) d, COUNT(*) t FROM entity e LEFT JOIN sam_entity s ON s.uei = e.uei",
          'chunk' => 800, 'unit' => 'UEIs'],
     ],
     // The rate-limited SAM Entity API (~1,000 req/day, one key shared local+prod). Usage is
     // estimated from today's per-UEI work logged (~per_req entities/request); prod-side only.
     // SAM's ENTITY API (detail/unregistered) is ~1,000 requests/DAY on this key's tier (user-confirmed;
     // no-role tier is 10/day). Distinct endpoints on the same key have DIFFERENT caps: SAM's FSRS/subaward
     // API is ~1,000/HOUR (a ~2,600-req/3h backfill succeeds — impossible under a daily cap), and FAC's
     // api.data.gov is ~1,000/hour. Count the Entity-API per-request scopes since UTC midnight; the bulk
     // monthly extract is a file download (not per-request), so it's excluded.
     // truth='counted': SAM reports no usage header on a 200 (only a 429 when over the daily cap),
     // so the sub-jobs log their ACTUAL request count in sync_log.requests and we SUM that since UTC
     // midnight — exact for our side, though SAM never confirms it. A 429 records the reset time.
     'quota' => ['limit' => 1000, 'window' => 'day', 'scopes' => ['detail', 'unregistered'], 'per_req' => 10, 'truth' => 'counted', 'label' => 'SAM Entity API'],
    ],
    ['key' => 'subawards', 'label' => 'Subaward passthrough (FSRS)', 'script' => 'build_subaward_edge.php', 'log' => 'subedge',
     'group' => 'source', 'origin' => 'SAM Subaward Reporting (FSRS) API', 'authority' => 'FFATA · 2 CFR part 170', 'cadence' => 'Detail mirrored locally; aggregate built + pushed', 'fresh' => 14,
     'desc' => 'Prime-to-sub pass-through flows powering the Passthrough tab. The full FSRS detail mirror (sam_assistance_subaward, ~2.8 GB) is too large for prod, so it lives only locally; the tab reads the pre-aggregated subaward_edge (one row per prime↔sub↔year) plus an entity-type lookup, built locally and shipped to prod. Freshness reflects the last build/push of the aggregate.',
     // subaward_edge first: it's the populated prod table. sam_assistance_subaward shows
     // its real count locally (the mirror) and 0 on prod (off-prod by design).
     'tables' => ['subaward_edge', 'subaward_entity_type', 'sam_assistance_subaward']],
    ['key' => 'usaspending', 'label' => 'USAspending awards', 'script' => 'sync_usa.php', 'ts' => ['usa_recipient', 'last_synced'],
     'group' => 'source', 'origin' => 'api.usaspending.gov (per-recipient)', 'authority' => 'DATA Act of 2014', 'cadence' => 'Nightly (findings + rollup members)', 'fresh' => 4,
     'desc' => 'Prime federal award obligations per recipient, fetched per-UEI and cached; transactions split by action date for fiscal-year accuracy.',
     'tables' => ['usa_award', 'usa_award_txn_month', 'usa_award_outlay_month', 'usa_award_cfda', 'usa_recipient'],
     // Best-effort tail jobs (staggered --oldest crawl) — reaped runs were previously invisible. Each
     // now carries a done/total coverage bar (like the SAM detail enrichment): the two crawls are
     // per-RECIPIENT (recipients synced / the sync universe); the File C outlay pull is per-AWARD.
     'subjobs' => [
         // recipients whose prime awards are cached / the sync universe = findings recipients UNION
         // rollup component agencies (fac_additional_ueis) — the exact set sync_usa.php --oldest cycles.
         ['scope' => 'prime_awards', 'label' => 'Prime award crawl (per recipient)', 'fresh' => 3,
          'unit' => 'recipients', 'chunk' => 700,
          'blurb' => 'The full look-up list — every audit-linked recipient (findings + rollup members) we query in USAspending. Reaching 100% means all were crawled, including the many that come back with no awards.',
          'coverage' => "SELECT SUM(r.last_synced IS NOT NULL) d, COUNT(*) t FROM (
                            SELECT DISTINCT auditee_uei uei FROM fac_findings WHERE auditee_uei IS NOT NULL
                            UNION
                            SELECT DISTINCT additional_uei uei FROM fac_additional_ueis WHERE additional_uei IS NOT NULL AND additional_uei <> ''
                          ) u LEFT JOIN usa_recipient r ON r.uei = u.uei"],
         // recipients that have per-transaction month data / recipients that have awards — the standing
         // gap that leaves the Comparative-by-Program view empty until the crawl reaches them (~58%).
         ['scope' => 'txn_months', 'label' => 'Transaction-month split (action-date)', 'fresh' => 3,
          'unit' => 'recipients', 'chunk' => 1500,
          'blurb' => 'Counts only recipients that came back WITH awards — so its total is smaller than the crawl target above. Many audit-linked recipients have no direct USAspending award (their money is pass-through, contracts, or has no recent activity).',
          // done = distinct recipients whose awards carry txn-month rows, counted via usa_award — the
          // SAME basis the tab itself joins on. (Was via usa_recipient, which lags usa_award on prod —
          // 17k rows vs 18.8k award-recipients — and undercounted coverage by ~5k, e.g. showing 39%
          // when the tab really had ~65%. EXISTS semi-join on the indexed award_id keeps it cheap.)
          'coverage' => "SELECT
                            (SELECT COUNT(DISTINCT recipient_uei) FROM usa_award a WHERE recipient_uei IS NOT NULL AND EXISTS(SELECT 1 FROM usa_award_txn_month m WHERE m.award_id = a.award_id)) d,
                            (SELECT COUNT(DISTINCT recipient_uei) FROM usa_award WHERE recipient_uei IS NOT NULL) t"],
         // Outlay-month coverage: reads usa_award_outlay_month directly (the pushed File C data),
         // so prod and local report the same way. Renders only once the table exists.
         ['scope' => 'outlay_months', 'label' => 'Outlay-month split (File C)', 'fresh' => 14,
          'unit' => 'awards', 'requires' => 'usa_award_outlay_month',
          'blurb' => 'Awards whose outlays are split into File C fiscal months (vs all non-loan awards with a lifetime outlay). FY2021–2025 was bulk-built locally and shipped via -PushTable; awards without monthly detail fall back to their lifetime figure on the tabs (award-level A tag).',
          'why' => 'The FY-split outlay source for the entity tabs. Not expected to reach 100%: pre-FY2021 outlays never appear in FY2021+ File C files, and some File C rows are unlinked on the USAspending side.',
          'cov_ttl' => 900,   // EXISTS probe per eligible award — never on the hot path
          // done = eligible awards THAT HAVE month rows (EXISTS, same universe as the total).
          // Counting DISTINCT award_ids in the table instead read 128% on prod: the pushed table
          // carries orphan rows for awards outside prod's HHS-scoped usa_award (harmless to the
          // app — every reader joins through usa_award — but not "coverage").
          'coverage' => "SELECT (SELECT COUNT(*) FROM usa_award a WHERE a.category <> 'loan' AND COALESCE(a.total_outlay,0) <> 0
                                   AND EXISTS(SELECT 1 FROM usa_award_outlay_month m WHERE m.award_id = a.award_id)) d,
                                (SELECT COUNT(*) FROM usa_award WHERE category <> 'loan' AND COALESCE(total_outlay,0) <> 0) t"],
         // LOCAL-ONLY twin of the bar above: live staging progress of the File C bulk matrix
         // itself (usa_outlay_dl_log exists only where the build runs). One slice = one
         // agency × FY × month cumulative download; parallel workers all land in the same log.
         ['scope' => 'filec_staging', 'label' => 'File C staging (bulk download matrix)', 'fresh' => 2,
          'unit' => 'awards', 'requires' => 'usa_outlay_dl_log',
          'blurb' => static function (PDO $pdo): string {
              try {
                  $sl = (int) $pdo->query('SELECT COUNT(*) FROM usa_outlay_dl_log')->fetchColumn();
                  $hr = (int) $pdo->query('SELECT COUNT(*) FROM usa_outlay_dl_log WHERE done_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR')->fetchColumn();
                  $ag = (int) $pdo->query('SELECT COUNT(*) FROM (SELECT agency FROM usa_outlay_dl_log GROUP BY agency HAVING COUNT(*) >= 55) a')->fetchColumn();
                  return number_format($sl) . ' of ~2,090 planned slices staged (38 agencies × 11 months × FY2021–25) · '
                       . $ag . ' agencies complete · ' . $hr . ' slice' . ($hr === 1 ? '' : 's')
                       . ' landed in the last hour. FY2026 catch-up runs before the difference pass.';
              } catch (\Throwable $e) { return 'File C bulk staging (local build).'; }
          },
          'why' => 'A one-time local bulk build, not a nightly job — File C is downloaded per agency × fiscal month (each file cumulative within its federal FY), filtered to our awards, and staged. Several parallel workers may be running at once; the facts line below is the live pulse.',
          'facts' => "SELECT 'Slices staged' k, COUNT(*) n FROM usa_outlay_dl_log
                      UNION ALL SELECT 'last hour', COUNT(*) FROM usa_outlay_dl_log WHERE done_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR
                      UNION ALL SELECT 'agencies complete', COUNT(*) FROM (SELECT agency FROM usa_outlay_dl_log GROUP BY agency HAVING COUNT(*) >= 55) a
                      UNION ALL SELECT 'rows staged', COALESCE(SUM(staged),0) FROM usa_outlay_dl_log",
          'facts_note' => 'The award-coverage bar counts awards with at least one staged outlay figure. It will not reach 100%: awards fully outlaid before FY2021 never appear in FY2021+ File C files (each FY file is cumulative within that year only), and some File C rows are unlinked on USAspending\'s side (no award key). Low-to-mid 90s after the FY2026 pass is the realistic ceiling.',
          'cov_ttl' => 900,   // 12.9M-row DISTINCT scan vs live inserts — never on the hot path
          'coverage' => "SELECT (SELECT COUNT(DISTINCT award_id) FROM usa_award_outlay_cpe) d,
                                (SELECT COUNT(*) FROM usa_award WHERE category <> 'loan' AND COALESCE(total_outlay,0) <> 0) t",
          // By-agency drill-down (cached 10 min — see the breakdown mechanism below). Names are
          // USAspending toptier DOWNLOAD ids (NOT CGAC codes: HHS is 68 here, not 075) — display-only
          // static map of the 38 agencies our awards touch, from bulk_download/list_agencies.
          'breakdown' => static function (PDO $pdo): array {
              $NAMES = [
                  '68' => 'Health and Human Services', '62' => 'Transportation', '76' => 'Housing and Urban Development',
                  '46' => 'National Science Foundation', '80' => 'Education', '14' => 'Agriculture', '16' => 'Interior',
                  '78' => 'Energy', '72' => 'NASA', '17' => 'Justice', '27' => 'FCC', '61' => 'EPA', '18' => 'Labor',
                  '63' => 'Homeland Security', '22' => 'Treasury', '15' => 'Commerce', '65' => 'USAID',
                  '37' => 'Veterans Affairs', '66' => 'Small Business Administration', '136' => 'Museum & Library Services',
                  '53' => 'National Endowment for the Arts', '9' => 'Executive Office of the President', '126' => 'Defense',
                  '21' => 'State', '31' => 'Nuclear Regulatory Commission', '54' => 'National Endowment for the Humanities',
                  '77' => 'National Archives', '103' => 'Gulf Coast Restoration Council', '129' => 'Appalachian Regional Commission',
                  '28' => 'Social Security Administration', '121' => 'AmeriCorps (CNCS)', '97' => 'Election Assistance Commission',
                  '56' => 'Consumer Product Safety Commission', '111' => 'Delta Regional Authority', '120' => 'Denali Commission',
                  '25' => 'National Credit Union Administration', '118' => 'U.S. Agency for Global Media',
                  '109' => 'Millennium Challenge Corporation',
              ];
              $rows = [];
              $q = $pdo->query(
                  "SELECT agency, COUNT(*) done, COALESCE(SUM(staged),0) rws, MAX(done_at) last_done
                   FROM usa_outlay_dl_log GROUP BY agency"
              );
              $eastern = new \DateTimeZone('America/New_York');
              foreach ($q as $r) {
                  $last = null;
                  if ($r['last_done']) {
                      try { $last = (new \DateTime($r['last_done'], new \DateTimeZone('UTC')))->setTimezone($eastern)->format('M j · g:i A T'); }
                      catch (\Throwable $e) { $last = $r['last_done']; }
                  }
                  $rows[] = ['name' => $NAMES[(string) $r['agency']] ?? ('agency ' . $r['agency']),
                             'done' => (int) $r['done'], 'total' => 55,
                             'rows' => (int) $r['rws'], 'last' => $last];
              }
              // incomplete first (fewest slices first = most attention-worthy), then complete by data size
              usort($rows, static fn ($a, $b) =>
                  ($a['done'] >= $a['total']) <=> ($b['done'] >= $b['total'])
                  ?: ($a['done'] <=> $b['done'])
                  ?: ($b['rows'] <=> $a['rows']));
              return ['label' => 'Staging by agency (10-min snapshots · +n = slices since the previous one)', 'rows' => $rows];
          }],

     ]],
    ['key' => 'reference', 'label' => 'Reference catalogs', 'script' => 'sync_reference.php', 'ts' => ['assistance_listing', 'last_synced'],
     'group' => 'source', 'origin' => 'SAM Assistance Listings + Federal Hierarchy APIs', 'authority' => 'OMB program catalog (CFDA/ALN)', 'cadence' => 'Weekly', 'fresh' => 9,
     'desc' => 'Assistance Listings (ALN/CFDA program catalog, active + inactive) and federal agency names.',
     'tables' => ['assistance_listing', 'federal_agency'],
     // Full weekly reloads (not backfills), so run history + interrupted detection, no progress bar.
     'subjobs' => [
         ['scope' => 'listings', 'label' => 'Assistance Listings (ALN/CFDA)', 'fresh' => 12],
         ['scope' => 'agencies', 'label' => 'Federal agency hierarchy',      'fresh' => 12],
     ]],
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
// Counts are cached ~60s: an exact COUNT(*) runs for every table on every status poll, and on the
// ~1M-row USAspending/FAC tables that's slow — especially while a backfill saturates the DB (it dragged
// the whole endpoint to 20s+). A minute-stale admin row count is fine; recompute at most once per TTL.
$countCacheFile = dirname(__DIR__) . '/cache/table_counts.json';
$countCache = (is_file($countCacheFile) && (time() - filemtime($countCacheFile)) < 60)
    ? (json_decode((string) file_get_contents($countCacheFile), true) ?: [])
    : [];
$rowCount = function (string $t, int $estimate) use ($pdo, &$countCache, $countCacheFile): int {
    if (array_key_exists($t, $countCache)) return (int) $countCache[$t];   // warm: no query
    $n = $estimate >= 1500000                                              // genuinely huge: COUNT too slow, estimate is fine
        ? $estimate
        : (int) (static function () use ($pdo, $t, $estimate) {
              try { return $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); } catch (Throwable $e) { return $estimate; }
          })();
    $countCache[$t] = $n;
    cache_put($countCacheFile, $countCache);
    return $n;
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
        'usa_award_outlay_month' => 'Per-award monthly OUTLAY amounts, reconstructed from File C account data (cumulative-within-federal-FY figures differenced into calendar months) — lets the USAspending tab show outlays on any fiscal year, including a non-federal entity FY, the same way txn months do for obligations.',
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
// Whole-payload micro-cache (25s): the Settings page polls frequently, and a COLD rebuild
// under backfill load can take ~30s on the single-threaded local server — without this,
// overlapping polls queue behind the rebuild and the whole app feels frozen. One rebuild
// per window; every other poll is instant. Slight staleness is fine for a status page.
$statusCache = dirname(__DIR__) . '/cache/admin_status.json';
if (is_file($statusCache) && (time() - filemtime($statusCache)) < 25) {
    $cached = cache_get($statusCache);
    if ($cached !== null) json_out($cached);   // else: torn/empty cache — fall through and recompute
}
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
// 'running' is excluded: a live run hasn't skipped anything (it's just not done), and a
// reaper-orphaned row stuck at 'running' is an interrupted run that resumes — both were
// polluting this card as scary "running / never" rows. nightly_step markers are the
// nightly's internal checklist (table_name NULL), not table syncs — excluded too.
$partials = [];
foreach ($pdo->query("SELECT s.source, s.table_name, s.scope, s.status, s.message, s.finished_at
                      FROM sync_log s
                      JOIN (SELECT source, table_name, MAX(id) mid FROM sync_log GROUP BY source, table_name) m ON m.mid = s.id
                      WHERE s.status NOT IN ('ok', 'running') AND s.status IS NOT NULL
                        AND s.source NOT IN ('nightly', 'nightly_step')
                      ORDER BY s.finished_at DESC") as $r) {
    $partials[] = [
        'source' => $r['source'], 'table' => $r['table_name'], 'scope' => $r['scope'],
        'status' => $r['status'], 'message' => $r['message'], 'finished_at' => $r['finished_at'],
    ];
}

// Tables intentionally empty/absent on prod — local-only mirrors or admin-only detail kept off
// the quota-bound prod DB. Their emptiness there is by design, so the coverage floor skips them
// (but still flags them locally, where they SHOULD hold data).
$LOCAL_ONLY = ['fac_passthrough', 'fac_notes_to_sefa', 'sam_assistance_subaward', 'usa_award_cfda',
               // exists on prod (migration ships with main and re-applies) but stays EMPTY until the
               // File C outlay build finishes locally and lands via -PushTable — not an incomplete sync.
               'usa_award_outlay_month'];

$out = [];
foreach ($SOURCES as $s) {
    // Exact COUNT(*) for small/moderate tables, estimate for the huge ones (see $rowCount).
    // 'empty' = a table that should hold data but has none — the signal that a sync sub-job never
    // ran (e.g. SAM detail enrichment leaving sam_business_type / sam_entity_naics at 0).
    $tables = array_map(function ($t) use ($rows, $rowCount, $LOCAL_ONLY, $isLocal) {
        $n = $rowCount($t, (int) ($rows[$t] ?? 0));
        $expectEmpty = !$isLocal && in_array($t, $LOCAL_ONLY, true);
        return ['name' => $t, 'rows' => $n, 'empty' => $n === 0 && !$expectEmpty];
    }, $s['tables']);
    $incomplete = false;
    foreach ($tables as $t2) { if ($t2['empty']) { $incomplete = true; break; } }

    // Sub-job lifecycle + backfill progress. These per-UEI jobs are multi-week BACKFILLS, not
    // one-shot syncs, so showing only the last run reads as "done" when it's a few % in. For each we
    // report: coverage (done/total, %, est. nights left at the nightly rate), the LATEST run's
    // outcome, and a short run history — with reaped/timed-out runs surfaced as INTERRUPTED (a
    // 'running' row that never got a finished_at) instead of silently reading "fine".
    $runState = function ($status, $started, $progress = null) {
        $st = (string) $status;
        if ($st === 'ok' || $st === 'error') return $st;
        if ($st === 'running') {
            // interrupted = stalled: no progress heartbeat in ~12 min (a live run bumps progress_at
            // each batch). Falls back to started_at + 60 min for rows written before progress_at existed.
            $alive = $progress ?: $started;
            $idle  = $alive ? (time() - strtotime($alive . ' UTC')) : PHP_INT_MAX;
            return $idle < ($progress ? 720 : 3600) ? 'running' : 'interrupted';
        }
        return $st !== '' ? $st : 'unknown';
    };
    $subjobs = [];
    foreach (($s['subjobs'] ?? []) as $sj) {
        // Skip a sub-job whose feature isn't deployed here — its sentinel table is absent. The outlay
        // pipeline is local-only (off prod for quota), so the File C bar doesn't render on prod at all.
        if (!empty($sj['requires'])) {
            $has = false;
            try { $has = (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($sj['requires']))->fetchColumn(); }
            catch (\Throwable $e) { $has = false; }
            if (!$has) continue;
        }
        // recent run history (newest first), each interpreted to a lifecycle state
        $runs = [];
        try {
            // 24 (not 8) so a parallel backfill's concurrent shard runs are ALL visible at once — the
            // File C outlay crawl can run ~20 sharded workers, each logging its own run.
            $h = $pdo->prepare("SELECT status, rows_upserted, started_at, progress_at, finished_at, message
                                FROM sync_log WHERE source = ? AND scope = ? ORDER BY id DESC LIMIT 24");
            $h->execute([$s['key'], $sj['scope']]);
            foreach ($h as $r) {
                $rstate = $runState($r['status'] ?? '', $r['started_at'] ?? null, $r['progress_at'] ?? null);
                $msg    = (string) ($r['message'] ?? '');
                // one/two-word plain-language "why" for a run that didn't just complete cleanly
                $reason = null;
                if ($rstate === 'interrupted') {
                    $reason = stripos($msg, 'quota') !== false ? 'API limit'
                            : (stripos($msg, 'timeout') !== false || stripos($msg, 'timed out') !== false ? 'timed out'
                            : 'time limit');
                } elseif ($rstate === 'error') {
                    $reason = stripos($msg, '429') !== false ? 'API limit' : 'error';
                } elseif ($rstate === 'running') {
                    $reason = 'in progress';
                }
                $runs[] = [
                    'state'       => $rstate,
                    'reason'      => $reason,
                    'rows'        => $r['rows_upserted'] !== null ? (int) $r['rows_upserted'] : null,
                    'started_at'  => $r['started_at'] ?? null,
                    'progress_at' => $r['progress_at'] ?? null,
                    'finished_at' => $r['finished_at'] ?? null,
                    'message'     => $r['message'] ?? null,
                ];
            }
        } catch (Throwable $e) { /* table/columns absent */ }
        $last = $runs[0] ?? null;

        // backfill coverage (done / total → %, remaining, est. nights at the nightly chunk rate),
        // cached ~60s (see coverage_cached) so the ~1M-row scans don't run on every status poll.
        $cov = null;
        if (!empty($sj['coverage'])) {
            $c = coverage_cached($pdo, $s['key'] . '_' . $sj['scope'], $sj['coverage'], (int) ($sj['cov_ttl'] ?? 60));
            if ($c) {
                {
                    $done = $c['d']; $tot = $c['t']; $rem = max(0, $tot - $done);
                    $cov = [
                        'done' => $done, 'total' => $tot, 'remaining' => $rem,
                        'pct'    => $tot > 0 ? (int) floor(100 * $done / $tot) : null,
                        // Suppress the nightly-chunk ETA while a run is ACTIVELY in progress: during a
                        // manual/parallel backfill (e.g. the File C outlay crawl on N sharded workers) the
                        // "nights at the nightly rate" math is meaningless — it reads hundreds of nights for
                        // a job finishing in hours. It returns once the job is idle / nightly-paced again.
                        'nights' => ($rem > 0 && !empty($sj['chunk']) && ($last['state'] ?? '') !== 'running')
                            ? (int) ceil($rem / $sj['chunk']) : 0,
                        'unit'   => $sj['unit'] ?? 'rows',
                    ];
                }
            }
        }

        // headline: running > (unfinished backfill) backfilling > ok; plus a problem flag for a hard
        // error, or an interrupted run on a job that has no gradual-backfill coverage model (see below).
        $lastState  = $last['state'] ?? 'never';
        $backfilled = $cov && $cov['remaining'] === 0;   // nothing left to enrich
        if ($lastState === 'running')            $head = 'running';
        elseif ($cov && $cov['remaining'] > 0)   $head = 'backfilling';
        elseif ($backfilled)                     $head = 'ok';
        elseif ($lastState === 'ok')             $head = 'ok';
        else                                     $head = $lastState;   // never / error / interrupted
        // A hard error is always a problem. For a gradual backfill (has a coverage model), an
        // INTERRUPTED run is the designed 28-min nightly cadence, not a fault — the progress bar and
        // "gradual catch-up" why already tell that story, so it's never a red alarm (whether still
        // backfilling or 100% done). Only flag an interrupted run when there's NO coverage model at
        // all (a one-shot that was expected to finish in a single run and got cut short).
        $problem = $lastState === 'error' || ($lastState === 'interrupted' && !$cov);

        // freshness: a completed run ages from finished_at; a problem or no run is stale regardless
        $refAt = $last['finished_at'] ?? ($last['started_at'] ?? null);
        $age   = $refAt ? (time() - strtotime($refAt . ' UTC')) / 86400 : null;
        $stale = $problem || $lastState === 'never' || ($lastState === 'ok' && $age !== null && $age > $sj['fresh']);

        // The WHY behind the headline — plain language, so daily activity next to a 9% bar reads
        // as sensible (each night adds one chunk) rather than contradictory. (The history dropdown
        // shows per-DAY activity bars, not per-run status labels — don't reference run labels here.)
        $unit = $cov['unit'] ?? ($sj['unit'] ?? 'rows');
        $why  = null;
        if ($head === 'running') {
            // rows_upserted counts RECORDS written (e.g. award-months), not the coverage unit
            // (recipients) — say "records" so a live run doesn't read "96,688 recipients".
            $why = 'A run is in progress right now'
                 . ($last && $last['rows'] ? ' — ' . number_format($last['rows']) . ' records so far.' : '.');
        } elseif ($head === 'backfilling' && $cov) {
            $chunk = !empty($sj['chunk']) ? '~' . number_format($sj['chunk']) : 'a';
            $why = "A gradual catch-up — each nightly run works through a batch of $chunk $unit; the"
                 . ' activity below shows what each day added. ' . number_format($cov['done'])
                 . ' of ' . number_format($cov['total']) . " $unit done ({$cov['pct']}%)"
                 . ($cov['nights'] ? ", about {$cov['nights']} more night" . ($cov['nights'] === 1 ? '' : 's') . ' to reach 100%.' : '.');
        } elseif ($head === 'interrupted' || $problem) {
            $why = 'The last run was stopped before it finished'
                 . ($last && $last['rows'] ? ' (at ' . number_format($last['rows']) . " $unit)" : '')
                 . ' — the shared server ends any nightly job that runs past ~28 minutes, so this'
                 . ' catch-up step got cut short. It picks up where it left off on the next run.';
        } elseif ($head === 'ok') {
            $why = ($cov && $cov['remaining'] === 0)
                ? 'Fully backfilled — nothing left to enrich; refreshes as new entities appear.'
                : 'Up to date — the last run finished cleanly and it re-runs on schedule.';
        } elseif ($head === 'never') {
            $why = 'Has not run under the new run-tracking yet — it will populate on the next scheduled run.';
        }
        // A subjob can hard-override the auto "why" (e.g. the outlay build is a one-time bulk job,
        // not a nightly backfill, so the auto-generated backfill text doesn't fit).
        if (!empty($sj['why'])) $why = $sj['why'];

        // Optional "what the job FOUND" facts line: config supplies SQL returning (k, n) pairs,
        // already labeled/ordered; rendered as "Active 57,986 · Lapsed 2,690 · …" under the bar.
        $facts = null;
        if (!empty($sj['facts'])) {
            try {
                $parts = [];
                foreach ($pdo->query($sj['facts']) as $fr) $parts[] = $fr['k'] . ' ' . number_format((float) $fr['n']);
                if ($parts) $facts = implode(' · ', $parts);
            } catch (\Throwable $e) { /* facts line just doesn't render */ }
        }

        // A blurb may be a closure computing live numbers (e.g. the detail-universe arithmetic).
        $blurb = $sj['blurb'] ?? null;
        if ($blurb instanceof \Closure) {
            try { $blurb = $blurb($pdo); } catch (\Throwable $e) { $blurb = null; }
        }

        // Optional per-key breakdown (e.g. File C staging by agency): a closure returning
        // ['label'=>…, 'rows'=>[{name,done,total,rows,last}…]]. Deliberately file-cached for
        // 10 MINUTES — it's a drill-down, not a pulse, and must never add load while the very
        // backfill it describes is saturating the DB.
        $break = null;
        if (!empty($sj['breakdown']) && $sj['breakdown'] instanceof \Closure) {
            $bfile = dirname(__DIR__) . '/cache/brk_' . preg_replace('/[^A-Za-z0-9_]/', '_', $s['key'] . '_' . $sj['scope']) . '.json';
            if (is_file($bfile) && (time() - filemtime($bfile)) < 600) {
                $break = json_decode((string) file_get_contents($bfile), true) ?: null;
            } else {
                // keep the expiring snapshot to diff against — each row gets a '+n since last
                // snapshot' delta so the 10-minute cadence reads as motion, not a static list
                $prev = is_file($bfile) ? json_decode((string) file_get_contents($bfile), true) : null;
                try {
                    $break = $sj['breakdown']($pdo);
                    if ($break !== null) {
                        $prevDone = [];
                        foreach (($prev['rows'] ?? []) as $pr) {
                            if (isset($pr['name'], $pr['done'])) $prevDone[$pr['name']] = (int) $pr['done'];
                        }
                        foreach ($break['rows'] as &$br) {
                            $d = isset($prevDone[$br['name']]) ? ((int) $br['done'] - $prevDone[$br['name']]) : 0;
                            if ($d > 0) $br['delta'] = $d;
                        }
                        unset($br);
                        $break['as_of'] = (new \DateTime('now', new \DateTimeZone('UTC')))
                            ->setTimezone(new \DateTimeZone('America/New_York'))->format('g:i A T');
                        @file_put_contents($bfile, json_encode($break));
                    }
                } catch (\Throwable $e) { $break = null; }
            }
        }

        $subjobs[] = [
            'scope' => $sj['scope'], 'label' => $sj['label'], 'blurb' => $blurb,
            'state' => $head, 'problem' => $problem, 'stale' => $stale, 'why' => $why,
            'coverage' => $cov,
            'facts' => $facts, 'facts_note' => $sj['facts_note'] ?? null,
            'breakdown' => $break,
            'last' => $last,   // {state,rows,started_at,finished_at,message} or null
            'runs' => $runs,   // recent history (newest first)
        ];
    }

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
    // Rate-limited-API quota status. Prefer GROUND TRUTH over the old rows÷per_req estimate:
    //   truth='header'  → FAC: api.data.gov returns X-RateLimit-Remaining (recorded to api_quota_obs
    //                     by FacClient). used = limit - remaining. mode 'api'.
    //   truth='counted' → SAM: no usage header, so sum the ACTUAL requests the sub-jobs logged
    //                     (sync_log.requests) over the window. mode 'counted'.
    //   otherwise / no data yet → fall back to rows÷per_req. mode 'estimate'.
    // Windowing matches the limit: hourly counts the last rolling hour, daily counts since UTC midnight.
    $quota = null;
    if (!empty($s['quota'])) {
        $qc     = $s['quota'];
        $win    = $qc['window'] ?? 'day';
        $lim    = (int) $qc['limit'];
        $truth  = $qc['truth'] ?? 'estimate';
        $bucket = $win === 'hour' ? 'UTC_TIMESTAMP() - INTERVAL 1 HOUR' : 'UTC_DATE()';
        $used = null; $mode = 'estimate'; $remaining = null; $observedAt = null; $resetAt = null; $note = null;

        // (a) always read any recorded observation — it carries a live remaining (FAC) and/or a
        //     limit-reached reset time (SAM 429). Guarded so a missing table can't 500 the console.
        $obs = null;
        try {
            $o = $pdo->prepare("SELECT limit_total, remaining, observed_at, reset_at, note FROM api_quota_obs WHERE source = ?");
            $o->execute([$s['key']]);
            $obs = $o->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) { /* api_quota_obs absent (pre-migration) */ }
        if ($obs) {
            // surface a limit-reached banner while the reset is still in the future
            if (!empty($obs['reset_at']) && strtotime($obs['reset_at'] . ' UTC') > time()) {
                $resetAt = $obs['reset_at'];
                $note    = $obs['note'] ?: 'rate limit reached';
            }
            // FAC ground truth: use the API's own remaining if it was observed within the window
            if ($truth === 'header' && $obs['remaining'] !== null && !empty($obs['observed_at'])) {
                $obsAge  = time() - strtotime($obs['observed_at'] . ' UTC');
                $inWin   = $win === 'hour' ? $obsAge < 3600 : $obsAge < 86400;
                if ($inWin) {
                    $remaining  = (int) $obs['remaining'];
                    if ($obs['limit_total'] !== null) $lim = (int) $obs['limit_total'];
                    $used       = max(0, $lim - $remaining);
                    $mode       = 'api';
                    $observedAt = $obs['observed_at'];
                }
            }
        }

        // (b) SAM: sum the real request count we logged this window
        if ($used === null && $truth === 'counted') {
            try {
                if (!empty($qc['scopes'])) {
                    $ph = implode(',', array_fill(0, count($qc['scopes']), '?'));
                    $q  = $pdo->prepare("SELECT SUM(requests), COUNT(requests) FROM sync_log
                                         WHERE source = ? AND scope IN ($ph) AND started_at >= $bucket");
                    $q->execute(array_merge([$s['key']], $qc['scopes']));
                } else {
                    $q = $pdo->prepare("SELECT SUM(requests), COUNT(requests) FROM sync_log
                                        WHERE source = ? AND started_at >= $bucket");
                    $q->execute([$s['key']]);
                }
                [$sumReq, $cntReq] = $q->fetch(PDO::FETCH_NUM);
                if ((int) $cntReq > 0) { $used = (int) $sumReq; $mode = 'counted'; }
            } catch (Throwable $e) { /* requests column absent (pre-migration) */ }
        }

        // (c) fallback: estimate from today's rows at ~per_req/request
        $entities = null;
        if ($used === null) {
            try {
                if (!empty($qc['scopes'])) {
                    $ph = implode(',', array_fill(0, count($qc['scopes']), '?'));
                    $q  = $pdo->prepare("SELECT COALESCE(SUM(rows_upserted), 0) FROM sync_log
                                         WHERE source = ? AND scope IN ($ph) AND started_at >= $bucket");
                    $q->execute(array_merge([$s['key']], $qc['scopes']));
                } else {
                    $q = $pdo->prepare("SELECT COALESCE(SUM(rows_upserted), 0) FROM sync_log
                                        WHERE source = ? AND started_at >= $bucket");
                    $q->execute([$s['key']]);
                }
                $entities = (int) $q->fetchColumn();
                $used     = (int) ceil($entities / max(1, (int) ($qc['per_req'] ?? 10)));
                $mode     = 'estimate';
            } catch (Throwable $e) { /* sync_log absent */ }
        }

        if ($used !== null) {
            $quota = [
                'label' => $qc['label'], 'window' => $win, 'mode' => $mode,
                'used' => $used, 'limit' => $lim,
                'pct' => $lim > 0 ? min(100, (int) round(100 * $used / $lim)) : 0,
                'remaining' => $remaining, 'observed_at' => $observedAt,
                'reset_at' => $resetAt, 'note' => $note, 'entities' => $entities,
            ];
        }
    }

    // Live pull strip + "last update pulled N" line. A sync that RunLogs a scope='pull' row
    // (currently sync_fac) surfaces here: 'live' while its running row has a fresh heartbeat
    // (30 min — a single big table can go ~15 min between per-table ticks on a year re-sync),
    // 'pull' = the latest completed pull's headline count for the always-visible summary line.
    $live = null; $pull = null;
    try {
        $lvStmt = $pdo->prepare("SELECT rows_upserted, message, started_at, progress_at FROM sync_log
                                 WHERE source = ? AND scope = 'pull' AND status = 'running' ORDER BY id DESC LIMIT 1");
        $lvStmt->execute([$s['key']]);
        if (($lv = $lvStmt->fetch(PDO::FETCH_ASSOC)) && !empty($lv['progress_at'])
            && (time() - strtotime($lv['progress_at'] . ' UTC')) < 1800) {
            $live = ['rows' => (int) $lv['rows_upserted'], 'message' => $lv['message'], 'started_at' => $lv['started_at']];
        }
        $plStmt = $pdo->prepare("SELECT rows_upserted, message, finished_at FROM sync_log
                                 WHERE source = ? AND scope = 'pull' AND status = 'ok' ORDER BY id DESC LIMIT 1");
        $plStmt->execute([$s['key']]);
        if ($pl = $plStmt->fetch(PDO::FETCH_ASSOC)) {
            $pull = ['rows' => (int) $pl['rows_upserted'], 'message' => $pl['message'], 'finished_at' => $pl['finished_at']];
        }
    } catch (Throwable $e) { /* strip just doesn't render */ }

    $out[] = [
        'key' => $s['key'], 'label' => $s['label'], 'desc' => $s['desc'],
        'group' => $s['group'] ?? 'source',
        'origin' => $s['origin'] ?? null, 'authority' => $s['authority'] ?? null,
        'cadence' => $s['cadence'] ?? null, 'fresh_days' => $s['fresh'] ?? 7,
        'chained' => !empty($s['chains']), 'job' => $job,
        'command' => 'php api/sync/' . $s['script'],
        'tables' => $tables,
        'incomplete' => $incomplete, 'subjobs' => $subjobs, 'quota' => $quota,
        'live' => $live, 'pull' => $pull,
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

// Live "Nightly run" panel: the most-recent run's step checklist (source='nightly_step', written
// by aero_nightly.sh via steplog.php). Steps within a run are minutes apart; a > 30-min gap between
// consecutive steps marks the run boundary. A step 'running' longer than 50 min (> any single step)
// with no finish = the run was reaped there → Interrupted; the per-source sub-jobs catch their own
// reap faster (~12 min via progress_at), so this high-level panel can afford the looser threshold.
$nightly_run = null;
$srows = $pdo->query("SELECT scope, status, started_at, progress_at, finished_at
                      FROM sync_log WHERE source = 'nightly_step' ORDER BY id DESC LIMIT 40")->fetchAll();
if ($srows) {
    $stepState = function ($status, $started, $progress) {
        $st = (string) $status;
        if ($st === 'ok' || $st === 'error') return $st;
        if ($st === 'running') {
            $alive = $progress ?: $started;
            $idle  = $alive ? (time() - strtotime($alive . ' UTC')) : PHP_INT_MAX;
            return $idle < 3000 ? 'running' : 'interrupted';   // 50 min > any single step
        }
        return 'unknown';
    };
    // One "run" = one UTC DAY of steps: the nightly executes as several cron WAVES spaced
    // ~45 min apart (see prod/aero_nightly.sh — each wave gets its own ~28-min reap window),
    // so the old gap-based grouping would splinter one night into per-wave panels.
    $run = []; $day = null;
    foreach ($srows as $r) {                                    // newest first
        $d = substr((string) $r['started_at'], 0, 10);
        if ($day === null) $day = $d;
        if ($d !== $day) break;
        $run[] = $r;
    }
    $steps = [];
    foreach (array_reverse($run) as $r) {                       // chronological
        $steps[] = [
            'label'       => $r['scope'],
            'state'       => $stepState($r['status'] ?? '', $r['started_at'] ?? null, $r['progress_at'] ?? null),
            'started_at'  => $r['started_at'],
            'finished_at' => $r['finished_at'],
            'ran_secs'    => null,
        ];
    }
    // A time-capped step has no finish stamp (SIGKILL isn't trappable), but the JOB it launched
    // heartbeats its own sync_log row (~every 30s) — so "how long it actually ran" is recoverable:
    // step start -> the job's last recorded activity. Rare (≤ a couple steps/day), so per-step query.
    foreach ($steps as &$s) {
        if ($s['state'] !== 'interrupted' || empty($s['started_at'])) continue;
        try {
            $q = $pdo->prepare(
                "SELECT MAX(GREATEST(started_at, COALESCE(progress_at, started_at), COALESCE(finished_at, started_at)))
                 FROM sync_log
                 WHERE source <> 'nightly_step' AND started_at >= ? AND started_at <= DATE_ADD(?, INTERVAL 10 MINUTE)"
            );
            $q->execute([$s['started_at'], $s['started_at']]);
            if (($lastAct = $q->fetchColumn())) {
                $ran = strtotime($lastAct . ' UTC') - strtotime($s['started_at'] . ' UTC');
                if ($ran > 0) $s['ran_secs'] = $ran;
            }
        } catch (\Throwable $e) { /* duration hint only */ }
    }
    unset($s);
    // Headline over the whole day: running if any step is live. A step that FAILED (hard error)
    // and a step that ran out of its ~28-min cron window are different stories — the reap is the
    // DESIGNED cadence for the long catch-up steps, so it gets a calm amber "time-capped", not a
    // red alarm; only a genuine error reads as failure. Neither is hidden by later clean waves.
    $running = null; $cut = null; $err = null;
    foreach ($steps as $s) {
        if ($s['state'] === 'running' && $running === null) $running = $s['label'];
        if ($s['state'] === 'error' && $err === null) $err = $s['label'];
        if ($s['state'] === 'interrupted' && $cut === null) $cut = $s['label'];
    }
    $state = $running !== null ? 'running' : ($err !== null ? 'error' : ($cut !== null ? 'timecap' : 'completed'));
    $st0   = $steps[0]['started_at'] ?? null;
    // While running, "elapsed" tracks the RUNNING step, not the day's first step — with the
    // waves spread across hours, day-start elapsed read as a 3.5h-old run during a 3-min wave.
    $ageRef = $st0;
    if ($running !== null) {
        foreach ($steps as $s) if ($s['state'] === 'running') { $ageRef = $s['started_at']; break; }
    }
    $why   = $state === 'running'
        ? "Currently on \"$running\"."
        : ($state === 'error'
            ? "\"$err\" failed — see the sync log; steps after it in the same wave may not have run."
            : ($state === 'timecap'
                ? "\"$cut\" used its full ~28-minute window — expected for the long catch-up steps, "
                  . "which work in nightly slices. It saved everything it processed and resumes in "
                  . "its next scheduled wave. Everything else finished cleanly."
                : 'All steps finished cleanly.'));
    $nightly_run = [
        'state'      => $state,
        'current'    => $running,
        'why'        => $why,
        'started_at' => $st0,
        'age_min'    => $ageRef ? (int) round((time() - strtotime($ageRef . ' UTC')) / 60) : null,
        'steps'      => $steps,
    ];
}

$statusPayload = [
    'sources'      => $out,
    'partials'     => $partials,
    'nightly'      => $nightly,
    'nightly_run'  => $nightly_run,
    'can_run'      => $isLocal,
    'total_rows'   => array_sum(array_column($out, 'total_rows')),
    'generated_at' => date('c'),
];
cache_put($statusCache, $statusPayload);
json_out($statusPayload);
