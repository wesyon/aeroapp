<?php
declare(strict_types=1);

/**
 * AERO — compute the per-recipient risk score (CLI).
 *
 * Assembles one entity bundle per UEI from the FAC data (latest audit + multi-year
 * history), runs the pure scorer in lib/Score.php, and upserts aero_score. Resubmitted
 * reports are deduped to the most-recently-accepted report per (uei, audit_year) so
 * findings are not double-counted.
 *
 * Usage:
 *   php compute_scores.php              # all recipients
 *   php compute_scores.php --uei=XXXX   # one recipient (on-demand recompute)
 */

ini_set('memory_limit', '1G');
$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
require $root . '/lib/Score.php';
require $root . '/lib/Rules.php';   // aero_deadline9 / aero_first_prior (unit-tested)
require $root . '/lib/Lineage.php'; // shared recurrence-depth kernel (same walk the routes use)
Env::load(dirname($root, 2) . '/.env');   // above web root (prod; first-set wins)
Env::load(dirname($root) . '/.env');      // repo root (local dev)

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$onlyUei = isset($args['uei']) ? (string) $args['uei'] : null;
$started = gmdate('Y-m-d H:i:s');   // all DB timestamps are stored UTC
$t0 = microtime(true);

$pdo = Db::connect();

// One run at a time (same guard as the pull syncs): two interleaved full runs would
// race the upsert loop against the stale-row reaper (DELETE ... computed_at < started).
// The lock auto-releases when the process exits.
if (!(int) $pdo->query("SELECT GET_LOCK('aero_compute_scores', 0)")->fetchColumn()) {
    fwrite(STDERR, "Another compute_scores.php run is already active; exiting.\n");
    exit(1);
}

// --- 0. multi-UEI governments (state_uei): alias every member to the group's CURRENT
// UEI (latest active filing), so the government is scored ONCE over its merged history.
// These are UEI successions (old UEI through FY2022, new from FY2023; fac_additional_ueis
// attests the linkage) — the union completes the timeline and lets repeat lineage cross
// the switch. The full-run reaper below then clears the old members' stale rows.
$alias = [];   // member uei => canonical (current) uei
foreach ($pdo->query("SELECT ueis FROM state_uei") as $r) {
    $set = array_values(array_filter(array_map('trim', preg_split('/\R+/', (string) $r['ueis']) ?: [])));
    if (count($set) < 2) continue;
    $in = implode(',', array_map([$pdo, 'quote'], $set));
    $cur = $pdo->query("SELECT auditee_uei FROM fac_general WHERE auditee_uei IN ($in) AND is_active = 1
                        ORDER BY audit_year DESC, fac_accepted_date DESC LIMIT 1")->fetchColumn();
    if (!$cur) continue;
    foreach ($set as $u) {
        if ($u !== $cur) $alias[$u] = $cur;
    }
}
$canon = fn (string $u): string => $alias[$u] ?? $u;

$ueiFilter = '';
if ($onlyUei !== null) {
    // a single-UEI recompute of a group member must load the WHOLE group's filings
    // (the bundle is keyed by the canonical UEI and needs the full history)
    $grpSet = [$onlyUei, $canon($onlyUei)];
    foreach ($alias as $m => $to) {
        if ($to === $canon($onlyUei)) $grpSet[] = $m;
    }
    $ueiFilter = ' AND auditee_uei IN (' . implode(',', array_map([$pdo, 'quote'], array_unique($grpSet))) . ')';
}

/** Days past the 2 CFR 200.512 deadline (lib/Rules.php; negative = early). */
function days_late(?string $fyEnd, ?string $submitted, ?string $accepted): ?int
{
    $sub = $submitted ? strtotime($submitted) : ($accepted ? strtotime($accepted) : null);
    if (!$fyEnd || !$sub) return null;
    return (int) floor(($sub - aero_deadline9($fyEnd)) / 86400);
}

/** Deepest lineage depths [overall, financial, subrecipient] considering only
 *  findings in audit years <= $maxYear — used to score each year for the trend.
 *  Depth comes from the shared kernel (lib/Lineage.php), as-of $maxYear. */
function chains_asof(array $repeatRefs, array $maps, array $fin, array $sub, int $maxYear): array
{
    $depth = 0; $finD = 0; $subD = 0;
    $ry = $maps['refYear'];
    foreach ($repeatRefs as $ref) {
        if (($ry[$ref] ?? 9999) > $maxYear) continue;
        $d = Lineage::walk($ref, $maps, $maxYear)['traced_depth'];
        $depth = max($depth, $d);
        if (isset($fin[$ref])) $finD = max($finD, $d);
        if (isset($sub[$ref])) $subD = max($subD, $d);
    }
    return [$depth, $finD, $subD];
}

// --- 1. finding aggregates per report_id -----------------------------------
echo "Loading finding aggregates...\n";
$find = [];
$fstmt = $pdo->query(
    "SELECT report_id,
        SUM(is_material_weakness=1) mw, SUM(is_significant_deficiency=1) sd,
        SUM(is_questioned_costs=1) qc, SUM(is_modified_opinion=1) modified,
        SUM(is_repeat_finding=1) repeat_n,
        SUM(is_material_weakness=1 AND is_repeat_finding=1) mw_repeat,
        SUM(is_material_weakness=1 AND type_requirement REGEXP '[BCJ]') fin_mw,
        SUM(type_requirement REGEXP '[BCJ]') fin_find,
        SUM(type_requirement REGEXP 'M') sub_find,
        SUM(is_material_weakness=1 AND type_requirement REGEXP 'M') sub_mw,
        COUNT(*) total_findings
     FROM fac_findings GROUP BY report_id"
);
foreach ($fstmt as $r) {
    $find[$r['report_id']] = [
        'mw' => (int) $r['mw'], 'sd' => (int) $r['sd'], 'qc' => (int) $r['qc'],
        'modified' => (int) $r['modified'], 'repeat' => (int) $r['repeat_n'],
        'mw_repeat' => (int) $r['mw_repeat'],
        'fin_mw' => (int) $r['fin_mw'], 'fin_find' => (int) $r['fin_find'],
        'sub_find' => (int) $r['sub_find'], 'sub_mw' => (int) $r['sub_mw'],
        'total_findings' => (int) $r['total_findings'],
    ];
}

// --- 1b. questioned-cost DOLLARS per report (component 3 severity) -----------
// Sum of trusted finding-text amounts (parse_findings.php); FAC's API carries only
// the is_questioned_costs boolean, not magnitude. 'suspect'/'unknown'/null excluded.
$qcDollars = [];
foreach ($pdo->query(
    "SELECT report_id, SUM(qc_amount) d FROM fac_finding_extract
     WHERE qc_amount > 0 AND qc_basis IN ('known','generic','flagged','inline')
     GROUP BY report_id") as $r) {
    $qcDollars[$r['report_id']] = (int) $r['d'];
}

// --- 2. active report per (uei, audit_year), with general/timeliness fields --
echo "Loading audits (deduping resubmissions)...\n";
$byUei = [];
// Timeliness is judged on the ORIGINAL filing for each (uei, audit_year): the
// earliest submitted_date across all its reports — so a later corrective
// resubmission doesn't make an on-time auditee look delinquent. Findings still
// come from the active (most-recently-accepted) report via rn = 1.
$astmt = $pdo->query(
    "SELECT report_id, uei, audit_year, fy_end_date, orig_submitted_date, fac_accepted_date, gc, mnc,
            auditee_name, auditee_state, entity_type, total_amount_expended, cognizant_agency FROM (
        SELECT report_id, auditee_uei uei, audit_year, fy_end_date,
               MIN(submitted_date) OVER (PARTITION BY auditee_uei, audit_year) orig_submitted_date,
               fac_accepted_date, auditee_name, auditee_state, entity_type, total_amount_expended, cognizant_agency,
               is_going_concern_included gc, is_material_noncompliance_disclosed mnc,
               ROW_NUMBER() OVER (PARTITION BY auditee_uei, audit_year
                                  ORDER BY fac_accepted_date DESC, report_id DESC) rn
        FROM fac_general WHERE auditee_uei IS NOT NULL$ueiFilter
     ) t WHERE rn = 1"
);
$zeros = ['mw' => 0, 'sd' => 0, 'qc' => 0, 'modified' => 0, 'repeat' => 0, 'mw_repeat' => 0,
          'fin_mw' => 0, 'fin_find' => 0, 'sub_find' => 0, 'sub_mw' => 0, 'total_findings' => 0];
$activeUei = [];   // report_id => uei   (active reports only)
$activeYear = [];  // report_id => audit_year
$collisions = [];  // multi-UEI groups that filed the same audit year under two member UEIs
$yrReport = [];    // canonical uei => year => [report_id, fac_accepted_date] (collision tiebreak)
foreach ($astmt as $r) {
    $uei = $canon($r['uei']);   // group members bucket under the canonical UEI
    $yr  = (int) $r['audit_year'];
    if (isset($yrReport[$uei][$yr])) {
        // Two member UEIs of a crosswalk group filed the SAME audit year (e.g. a
        // mid-year UEI switch with split filings). The bundle keys on (canonical,
        // year), so one report's findings would silently win — keep the most-
        // recently-accepted report (mirroring is_active within a UEI), drop the
        // other from this entity's aggregates, and flag the collision via sync_log
        // (status 'partial') so the console surfaces it. No such case exists in
        // the data as of 2026-06; this guard makes sure we HEAR about the first.
        [$prevRid, $prevAcc] = $yrReport[$uei][$yr];
        $keepNew = ((string) $r['fac_accepted_date']) > ((string) $prevAcc);
        $collisions[] = sprintf('%s FY%d: kept %s, dropped %s', $uei, $yr,
            $keepNew ? $r['report_id'] : $prevRid, $keepNew ? $prevRid : $r['report_id']);
        if (!$keepNew) continue;
        unset($activeUei[$prevRid], $activeYear[$prevRid]);   // un-register the loser
    }
    $yrReport[$uei][$yr] = [$r['report_id'], $r['fac_accepted_date']];
    $agg = $find[$r['report_id']] ?? $zeros;
    $byUei[$uei]['by_year'][$yr] = $agg + [
        'going_concern' => $r['gc'] !== null ? (bool) $r['gc'] : false,
        'material_noncompliance' => $r['mnc'] !== null ? (bool) $r['mnc'] : false,
        'days_late' => days_late($r['fy_end_date'], $r['orig_submitted_date'], $r['fac_accepted_date']),
        'qc_dollars' => $qcDollars[$r['report_id']] ?? 0,
        'expended' => $r['total_amount_expended'] !== null ? (int) $r['total_amount_expended'] : 0,
    ];
    $activeUei[$r['report_id']] = $uei;
    $activeYear[$r['report_id']] = $yr;
    // track the report_id + display fields for the entity's latest year (>= so a
    // same-year collision WINNER refreshes fields a dropped loser may have set)
    if (!isset($byUei[$uei]['latest_year']) || $yr >= $byUei[$uei]['latest_year']) {
        $byUei[$uei]['latest_year'] = $yr;
        $byUei[$uei]['latest_report'] = $r['report_id'];
        $byUei[$uei]['name'] = $r['auditee_name'];
        $byUei[$uei]['state'] = $r['auditee_state'];
        $byUei[$uei]['entity_type'] = $r['entity_type'];
        $byUei[$uei]['federal_latest'] = $r['total_amount_expended'];
        $byUei[$uei]['cognizant'] = $r['cognizant_agency'];
    }
}

// HHS footprint: which recipients have any ALN-93 (HHS) federal award
$hhs = [];
foreach ($pdo->query("SELECT DISTINCT auditee_uei u FROM fac_federal_awards WHERE federal_agency_prefix='93' AND auditee_uei IS NOT NULL") as $r) {
    $hhs[$canon($r['u'])] = true;
}

// --- 2b. repeat-finding lineage depth (replaces the year-level chain) --------
// Walk prior_finding_ref_numbers across an entity's active reports to find the
// deepest single-issue chain (distinct audit years one finding traces through).
// A flagged repeat whose prior predates our window still counts as depth >= 2.
echo "Tracing repeat-finding lineage...\n";
$prior = [];      // uei => [ref => first prior ref]  (repeat findings only)
$refYear = [];    // uei => [ref => audit_year]
$rep = [];        // uei => [ref => 0|1]  (kernel input: is_repeat / depth floor)
$repeatRefs = []; // uei => [ref, ...]
$finRepeat = [];  // uei => [ref => true]  (financial B/C/J repeat findings, for cash_financial)
$subRepeat = [];  // uei => [ref => true]  (subrecipient type-M repeat findings, for subrecipient)
foreach ($pdo->query("SELECT report_id, reference_number ref, prior_finding_ref_numbers pr, is_repeat_finding rep, type_requirement ty FROM fac_findings") as $f) {
    $rid = $f['report_id'];
    if (!isset($activeUei[$rid])) continue;   // ignore superseded resubmissions
    $uei = $activeUei[$rid];
    $refYear[$uei][$f['ref']] = $activeYear[$rid];
    $rep[$uei][$f['ref']]     = (int) $f['rep'];
    if ((int) $f['rep'] === 1) {
        $prior[$uei][$f['ref']] = aero_first_prior($f['pr']);
        $repeatRefs[$uei][] = $f['ref'];
        if (preg_match('/[BCJ]/', (string) $f['ty'])) $finRepeat[$uei][$f['ref']] = true;
        if (preg_match('/M/', (string) $f['ty']))     $subRepeat[$uei][$f['ref']] = true;
    }
}
foreach ($byUei as $uei => &$b) {
    // same shared kernel as the routes — boundary credit, cycle guard, ceiling all defined
    // there, so the scored repeat depth agrees with the Evaluation levels by construction.
    $maps = ['refYear' => $refYear[$uei] ?? [], 'prior' => $prior[$uei] ?? [], 'rep' => $rep[$uei] ?? []];
    $fin = $finRepeat[$uei] ?? [];
    $sub = $subRepeat[$uei] ?? [];
    $depth = 0;
    $finDepth = 0;   // deepest lineage among financial (B/C/J) repeat findings
    $subDepth = 0;   // deepest lineage among subrecipient (type M) repeat findings
    foreach ($repeatRefs[$uei] ?? [] as $ref) {
        $d = Lineage::walk($ref, $maps)['traced_depth'];
        $depth = max($depth, $d);
        if (isset($fin[$ref])) $finDepth = max($finDepth, $d);
        if (isset($sub[$ref])) $subDepth = max($subDepth, $d);
    }
    $b['lineage_depth'] = $depth;
    $b['fin_chain'] = $finDepth;
    $b['sub_chain'] = $subDepth;
}
unset($b);

// --- 3. pass-through activity (subaward primes) + finding-text presence ------
// sam_assistance_subaward is the ~2.8 GB FSRS detail mirror kept LOCAL-only (off the
// quota-bound prod DB by design), so on prod the table is absent — degrade to "no
// pass-through info" rather than fataling the whole rescore (which would abort the nightly
// before its cache-bust / ANALYZE / weekly OPTIMIZE steps). The subrecipient component
// still scores from type-M findings; has_passthrough is informational. Mirrors the
// usa_award_cfda guard in routes/grantee.php.
$hasPt = [];
try {
    foreach ($pdo->query("SELECT DISTINCT prime_entity_uei u FROM sam_assistance_subaward WHERE prime_entity_uei IS NOT NULL") as $r) {
        $hasPt[$canon($r['u'])] = true;
    }
} catch (\PDOException $e) {
    fwrite(STDERR, "pass-through detection skipped (sam_assistance_subaward absent — local-only): " . $e->getMessage() . "\n");
}
$hasText = [];
foreach ($pdo->query("SELECT DISTINCT report_id r FROM fac_findings_text") as $r) {
    $hasText[$r['r']] = true;
}
// An extract row also proves the narrative was published: prod's data dump ships
// fac_finding_extract but not the bulky fac_findings_text, so without this the CAP
// component's 'available' flag would differ between local and prod scoring runs.
foreach ($pdo->query("SELECT DISTINCT report_id r FROM fac_finding_extract") as $r) {
    $hasText[$r['r']] = true;
}

// --- 4. CAP coverage/quality for each entity's latest report ----------------
echo "Analyzing corrective action plans...\n";
$latestReports = [];
foreach ($byUei as $b) {
    if (isset($b['latest_report'])) $latestReports[$b['latest_report']] = true;
}
$capAgg = [];   // report_id => [count, qsum]
foreach ($pdo->query("SELECT report_id, planned_action FROM fac_corrective_action_plans") as $r) {
    $rid = $r['report_id'];
    if (!isset($latestReports[$rid])) continue;
    $txt = (string) ($r['planned_action'] ?? '');
    if (trim($txt) === '') continue;
    if (!isset($capAgg[$rid])) $capAgg[$rid] = ['count' => 0, 'qsum' => 0.0];
    $capAgg[$rid]['count']++;
    $capAgg[$rid]['qsum'] += Score::capQualityScore($txt);
}

// --- 5. score every entity + upsert -----------------------------------------
echo "Scoring " . count($byUei) . " recipients...\n";
$up = new Upserter($pdo);
$n = 0;
$tierCount = [];
$pdo->beginTransaction();
foreach ($byUei as $uei => $b) {
    $ly = $b['latest_year'];
    $rid = $b['latest_report'];
    $total = $b['by_year'][$ly]['total_findings'];
    $cc = $capAgg[$rid] ?? ['count' => 0, 'qsum' => 0.0];
    $available = isset($hasText[$rid]) || $cc['count'] > 0;
    $coverage = $total > 0 ? min(1.0, $cc['count'] / $total) : 0.0;
    $quality  = $cc['count'] > 0 ? $cc['qsum'] / $cc['count'] : 0.0;

    $res = Score::compute([
        'latest_year' => $ly,
        'years' => array_keys($b['by_year']),
        'has_passthrough' => isset($hasPt[$uei]),
        'lineage_depth' => $b['lineage_depth'] ?? 0,
        'fin_chain' => $b['fin_chain'] ?? 0,
        'sub_chain' => $b['sub_chain'] ?? 0,
        'by_year' => $b['by_year'],
        'cap' => ['coverage' => $coverage, 'quality' => $quality, 'available' => $available],
    ]);
    $s = $res['subscores'];

    // per-year trend: composite as of each audit year (chains/cap restricted to <= Y)
    $yrs = array_keys($b['by_year']);
    sort($yrs);
    $trend = [];
    foreach ($yrs as $Y) {
        [$dep, $finD, $subD] = chains_asof($repeatRefs[$uei] ?? [],
            ['refYear' => $refYear[$uei] ?? [], 'prior' => $prior[$uei] ?? [], 'rep' => $rep[$uei] ?? []],
            $finRepeat[$uei] ?? [], $subRepeat[$uei] ?? [], $Y);
        $byY = array_filter($b['by_year'], fn ($k) => $k <= $Y, ARRAY_FILTER_USE_KEY);
        $capY = ($Y === $ly) ? ['coverage' => $coverage, 'quality' => $quality, 'available' => $available]
                             : ['coverage' => 0.0, 'quality' => 0.0, 'available' => false];
        $rY = Score::compute([
            'latest_year' => $Y, 'years' => array_keys($byY), 'has_passthrough' => isset($hasPt[$uei]),
            'lineage_depth' => $dep, 'fin_chain' => $finD, 'sub_chain' => $subD,
            'by_year' => $byY, 'cap' => $capY,
        ]);
        $trend[] = ['year' => $Y, 'composite' => $rY['composite'], 'tier' => $rY['tier']];
    }

    $up->insert('aero_score', [
        'uei' => $uei,
        'latest_audit_year' => $ly,
        'recipient_name' => $b['name'] ?? null,
        'entity_type' => $b['entity_type'] ?? null,
        'state' => $b['state'] ?? null,
        'audit_count' => count($b['by_year']),
        'federal_latest' => $b['federal_latest'] ?? null,
        'cognizant_agency' => $b['cognizant'] ?? null,
        'is_hhs' => isset($hhs[$uei]) ? 1 : 0,
        'trend' => json_encode($trend, JSON_UNESCAPED_SLASHES),
        'sc_internal_control' => $s['internal_control'],
        'sc_repeat_findings' => $s['repeat_findings'],
        'sc_questioned_costs' => $s['questioned_costs'],
        'sc_reporting_timeliness' => $s['reporting_timeliness'],
        'sc_cash_financial' => $s['cash_financial'],
        'sc_subrecipient' => $s['subrecipient'],
        'sc_cap_quality' => $s['cap_quality'],   // may be null
        'composite_score' => $res['composite'],
        'tier' => $res['tier'],
        'drivers' => json_encode($res['drivers'], JSON_UNESCAPED_SLASHES),
        'computed_at' => gmdate('Y-m-d H:i:s'),
    ]);
    $tierCount[$res['tier']] = ($tierCount[$res['tier']] ?? 0) + 1;
    if (++$n % 5000 === 0) { $pdo->commit(); $pdo->beginTransaction(); echo "  $n...\n"; }
}
$pdo->commit();

// A full run is the authoritative recipient set: reap rows this run did not touch
// (recipients that dropped out of FAC scope would otherwise keep stale scores
// forever, since the loop only upserts). --uei runs touch one row, so they skip this.
if ($onlyUei === null) {
    $stale = $pdo->exec("DELETE FROM aero_score WHERE computed_at < " . $pdo->quote($started));
    if ($stale) echo "  reaped $stale stale aero_score rows (no longer in FAC scope)\n";
}

// status 'partial' on collisions so the console's "Incomplete sync runs" panel
// surfaces them; a later clean run writes 'ok' and clears the flag.
$pdo->prepare(
    "INSERT INTO sync_log (source, scope, table_name, rows_upserted, status, message, started_at, finished_at)
     VALUES ('score', :scope, 'aero_score', :n, :status, :msg, :start, UTC_TIMESTAMP())"
)->execute([':scope' => $onlyUei ?? 'all', ':n' => $n, ':start' => $started,
            ':status' => $collisions ? 'partial' : 'ok',
            ':msg' => $collisions
                ? "same-year multi-UEI filings (kept most-recently-accepted):\n" . implode("\n", $collisions)
                : null]);
if ($collisions) {
    fwrite(STDERR, count($collisions) . " same-year multi-UEI collision(s) detected - see sync_log / Settings console\n");
}

printf("Done. %d recipients scored in %.1fs.\n", $n, microtime(true) - $t0);
foreach (['Clean', 'Minimal', 'Moderate', 'Elevated', 'Substantial', 'Severe'] as $t) {
    printf("  %-12s %6d  (%.1f%%)\n", $t, $tierCount[$t] ?? 0, $n ? 100 * ($tierCount[$t] ?? 0) / $n : 0);
}
