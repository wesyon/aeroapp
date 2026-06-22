<?php
declare(strict_types=1);
/**
 * GET /api/evaluation[?type=stategov|local|non-profit|...][&state=XX] — the 7-level
 * enforcement evaluation, one row per entity. Default scope is state governments
 * (state_uei registry, 50 states + DC); any other entity type evaluates the riskiest
 * 500 entities of that type (by AERO composite) so the response stays bounded.
 * Level logic mirrors the evaluation block in /api/grantee. EVERY type/state combo
 * (and the ?agg=states rollup) is disk-cached for 6h — recomputing per request made
 * type switching hostage to shared-host load (?fresh=1 recomputes, local console only).
 */

require_once dirname(__DIR__) . '/lib/Lineage.php';

$type  = q_str('type') ?? 'stategov';
$state = q_str('state');
if ($state !== null && !preg_match('/^[A-Za-z]{2}$/', $state)) $state = null;
$state = $state !== null ? strtoupper($state) : null;

$ENTITY_TYPES = ['local', 'non-profit', 'higher-ed', 'state', 'tribal', 'for-profit', 'foreign', 'unknown'];
$REGISTRY_TYPES = ['stategov', 'territory'];   // both come from the state_uei registry
if (!in_array($type, $REGISTRY_TYPES, true) && !in_array($type, $ENTITY_TYPES, true)) {
    json_out(['error' => 'unknown type', 'allowed' => array_merge($REGISTRY_TYPES, $ENTITY_TYPES)], 400);
}
$isAgg = !in_array($type, $REGISTRY_TYPES, true) && q_str('agg') === 'states';

// $type is whitelisted and $state regex-validated above, so both are filename-safe
$cacheFile = dirname(__DIR__) . '/cache/evaluation_' . $type . '_' . ($state ?? 'all') . ($isAgg ? '_agg' : '') . '.json';
$fresh = isset($_GET['fresh']) && is_local_request();
if (!$fresh && is_file($cacheFile) && (time() - filemtime($cacheFile)) < 21600) {
    json_out(json_decode((string) file_get_contents($cacheFile), true));
}

$CAP = 500;   // non-stategov scopes: riskiest N entities (by composite score)

// ---- candidate groups: [key => [state, label, uei(current/link), ueis[]]] ----------
$groups = [];
$capped = false;
$total = null;
$sgUeis = [];   // every state/territory-government UEI (excluded from the generic 'state' type)
$TERRITORIES = ['AS', 'GU', 'MP', 'PR', 'VI'];
foreach ($pdo->query("SELECT state_code, label, ueis FROM state_uei") as $r) {
    $set = array_values(array_filter(array_map('trim', preg_split('/\R+/', (string) $r['ueis']) ?: [])));
    array_push($sgUeis, ...$set);
    if (!in_array($type, $REGISTRY_TYPES, true)) continue;
    // 'stategov' = the 50 states + DC; 'territory' = the registry's territory rows
    $isTerr = in_array($r['state_code'], $TERRITORIES, true);
    if ($type === 'stategov' ? $isTerr : !$isTerr) continue;
    if ($state !== null && $r['state_code'] !== $state) continue;
    if ($set) $groups[$r['state_code']] = ['state' => $r['state_code'], 'label' => $r['label'], 'ueis' => $set];
}
if (!in_array($type, $REGISTRY_TYPES, true)) {
    // universe from the entity directory (score-independent); aero_score (s) joined only to rank/contextualize
    $w = ['e.entity_type = ?', 'e.latest_audit_year IS NOT NULL'];
    $p = [$type];
    if ($type === 'state' && $sgUeis) {   // generic "state" entities, minus the state govts
        $w[] = 'e.uei NOT IN (' . implode(',', array_fill(0, count($sgUeis), '?')) . ')';
        array_push($p, ...$sgUeis);
    }

    // ?agg=states — per-state cohort rollup for the v2 map (aero_score only: computing
    // the 7 levels for a whole entity type, e.g. 28k non-profits, is far too heavy, and
    // the capped entity list would bias a map). Unbiased counts + risk density per state.
    if ($isAgg) {
        $st = $pdo->prepare(
            "SELECT e.state, COUNT(*) n, ROUND(AVG(s.composite_score), 1) avg_score,
                    SUM(s.tier IN ('Elevated','Substantial','Severe')) high
             FROM entity e LEFT JOIN aero_score s ON s.uei = e.uei
             WHERE " . implode(' AND ', $w) . " AND e.state IS NOT NULL AND e.state <> ''
             GROUP BY e.state ORDER BY e.state"
        );
        $st->execute($p);
        $agg = [];
        foreach ($st as $r) {
            $agg[$r['state']] = ['n' => (int) $r['n'], 'high' => (int) $r['high'], 'avg_score' => (float) $r['avg_score']];
        }
        $out = ['agg' => $agg, 'type' => $type, 'generated_at' => date('c')];
        @mkdir(dirname($cacheFile), 0775, true);
        @file_put_contents($cacheFile, json_encode($out));
        json_out($out);
    }

    if ($state !== null) { $w[] = 'e.state = ?'; $p[] = $state; }
    $where = implode(' AND ', $w);
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM entity e WHERE $where");
    $cnt->execute($p);
    $total = (int) $cnt->fetchColumn();
    $capped = $total > $CAP;
    // riskiest N: rank by the (LEFT-joined) score — a missing/stale score sorts the entity
    // last rather than dropping it from the evaluable universe.
    $st = $pdo->prepare("SELECT e.uei, e.display_name recipient_name, e.state
                         FROM entity e LEFT JOIN aero_score s ON s.uei = e.uei WHERE $where
                         ORDER BY s.composite_score DESC, e.federal_latest DESC LIMIT $CAP");
    $st->execute($p);
    foreach ($st as $r) {
        $groups[$r['uei']] = ['state' => $r['state'], 'label' => $r['recipient_name'] ?: $r['uei'], 'ueis' => [$r['uei']]];
    }
}

$all = $groups ? array_merge(...array_column($groups, 'ueis')) : [];
if (!$all) json_out(['states' => [], 'type' => $type, 'total' => 0, 'capped' => false, 'generated_at' => date('c')]);
$in = implode(',', array_fill(0, count($all), '?'));

/** 2 CFR 200.512 deadline (lib/Rules.php, unit-tested; same source as grantee.php). */
$dl9 = static fn (string $fy): int => aero_deadline9($fy);

// (A) filings per uei/year — delinquency source (original submission per audit year)
$st = $pdo->prepare(
    "SELECT auditee_uei uei, audit_year yr, MAX(fy_end_date) fy, MIN(submitted_date) orig,
            MAX(audit_period_covered = 'biennial') bi
     FROM fac_general WHERE auditee_uei IN ($in) AND fy_end_date IS NOT NULL AND submitted_date IS NOT NULL
     GROUP BY auditee_uei, audit_year"
);
$st->execute($all);
$filings = [];                                  // uei => year => [fy, orig, bi]
foreach ($st as $r) $filings[$r['uei']][(int) $r['yr']] = ['fy' => $r['fy'], 'orig' => $r['orig'], 'bi' => (int) $r['bi'] === 1];

// (B) active reports per uei (resubmission-deduped; lineage + latest-audit scope)
$st = $pdo->prepare("SELECT auditee_uei uei, report_id, audit_year yr, auditor_firm_name firm FROM fac_general WHERE auditee_uei IN ($in) AND is_active = 1");
$st->execute($all);
$activeRep = [];                                // uei => report_id => year
$repFirm = [];                                  // report_id => audit firm
foreach ($st as $r) {
    $activeRep[$r['uei']][$r['report_id']] = (int) $r['yr'];
    if ($r['firm'] !== null && trim((string) $r['firm']) !== '') $repFirm[$r['report_id']] = trim((string) $r['firm']);
}

// (C) AERO scores (composite/tier for context; federal_latest gates missing-year
// delinquency; trend powers the momentum view)
$st = $pdo->prepare("SELECT uei, recipient_name, composite_score, tier, federal_latest, trend FROM aero_score WHERE uei IN ($in)");
$st->execute($all);
$scores = [];
foreach ($st as $r) $scores[$r['uei']] = $r;

// (C2) registration red flags: SAM status/expiry + active debarment exclusions
$samReg = [];
$st = $pdo->prepare("SELECT uei, registration_status, registration_expiration_date FROM sam_entity WHERE uei IN ($in)");
$st->execute($all);
foreach ($st as $r) $samReg[$r['uei']] = ['status' => $r['registration_status'], 'expires' => $r['registration_expiration_date']];
$excl = [];
$st = $pdo->prepare("SELECT uei_sam, COUNT(*) n FROM sam_exclusion WHERE uei_sam IN ($in)
                     AND (termination_date IS NULL OR termination_date > CURDATE()) GROUP BY uei_sam");
$st->execute($all);
foreach ($st as $r) $excl[$r['uei_sam']] = (int) $r['n'];

// (D) findings on active reports, with extracted questioned-cost dollars
$QC_TRUSTED = ['known', 'generic', 'flagged', 'inline'];
$st = $pdo->prepare(
    "SELECT f.auditee_uei uei, f.report_id, f.reference_number ref, f.audit_year yr,
            f.is_modified_opinion mo, f.is_material_weakness mw, f.is_repeat_finding rep,
            f.is_significant_deficiency sd, f.is_questioned_costs qcf,
            f.prior_finding_ref_numbers pr, f.type_requirement ty, e.qc_amount, e.qc_basis
     FROM fac_findings f
     JOIN fac_general g ON g.report_id = f.report_id AND g.is_active = 1
     LEFT JOIN fac_finding_extract e ON e.report_id = f.report_id AND e.finding_ref_number = f.reference_number
     WHERE f.auditee_uei IN ($in)"
);
$st->execute($all);
$findings = [];                                 // uei => finding rows
foreach ($st as $r) $findings[$r['uei']][] = $r;

$rows = [];
$latestRids = [];   // every group's latest active report — drives the program/agency rollups
$ridRow = [];       // report_id => index into $rows (per-row program/agency attribution)
foreach ($groups as $g) {
    // current UEI = most recent active audit year, tie-broken by federal $ size — the
    // link target + score row. All FAC data below merges across the WHOLE UEI set: state
    // governments are UEI successions (old UEI through FY2022, new from FY2023), so the
    // union gives the complete history and lets repeat lineage cross the UEI switch.
    // (Non-stategov groups are single-UEI, so the merge is a no-op for them.)
    $uei = null; $best = [-1, -1.0];
    foreach ($g['ueis'] as $u) {
        $ly = isset($activeRep[$u]) && $activeRep[$u] ? max($activeRep[$u]) : -1;
        $cand = [$ly, (float) ($scores[$u]['federal_latest'] ?? 0)];
        if ($cand > $best) { $best = $cand; $uei = $u; }
    }
    $f = []; $fBi = []; $reps = []; $fnd = []; $fedMax = 0.0;
    foreach ($g['ueis'] as $u) {
        foreach ($filings[$u] ?? [] as $yr => $x) {                            // per year keep the earliest original filing
            if (!isset($f[$yr]) || strtotime($x['orig']) < strtotime($f[$yr]['orig'])) $f[$yr] = $x;
            if ($x['bi']) $fBi[$yr] = true;                                    // biennial covers $yr-1 too (2 CFR 200.504)
        }
        $reps += $activeRep[$u] ?? [];
        if (isset($findings[$u])) $fnd = array_merge($fnd, $findings[$u]);
        $fedMax = max($fedMax, (float) ($scores[$u]['federal_latest'] ?? 0));
    }

    // Level 1 — NOT-FILED (missing & overdue) years only; late-filed years are tallied
    // and emitted as reference but no longer trigger the level (grantee.php matches).
    $late = 0; $missing = 0; $unverified = 0; $missingYrs = [];
    $likely = $fedMax >= 2000000;                                              // ~2x the FY25 $1M threshold
    if ($f) {
        foreach ($f as $x) {
            if ((strtotime($x['orig']) - $dl9($x['fy'])) / 86400 > 0) $late++;
        }
        $lastYr = max(array_keys($f));
        $lastFy = $f[$lastYr]['fy'];
        $fm = (int) date('n', strtotime($lastFy)); $fd = (int) date('j', strtotime($lastFy));
        for ($y = min(array_keys($f)) + 1; $y <= (int) date('Y'); $y++) {
            if (isset($f[$y])) continue;
            if (aero_biennial_covered($y, $fBi, $lastYr)) continue;            // inside a biennial period
            $fyEnd = date('Y-m-d', mktime(0, 0, 0, $fm, $fd, $y));
            if ($dl9($fyEnd) >= time()) break;                                 // trailing edge: not yet due
            if ($likely) { $missing++; $missingYrs[] = $y; }
            else $unverified++;
        }
    }

    // filing punch-card: per-year status over the last 6 audit years (same rules as the
    // L1 count above, but emitted for EVERY year so on-time/pending years show too)
    $filingYears = [];
    $nowY = (int) date('Y');
    $minY = $f ? min(array_keys($f)) : null;
    for ($y = $nowY - 5; $y <= $nowY; $y++) {
        if (!$f) { $filingYears[$y] = ['st' => 'na']; continue; }
        if (isset($f[$y])) {
            $days = (int) round((strtotime($f[$y]['orig']) - $dl9($f[$y]['fy'])) / 86400);
            $filingYears[$y] = $days > 0 ? ['st' => 'late', 'days' => $days] : ['st' => 'ontime'];
            continue;
        }
        // covered before the pre-history 'na': a biennial's prior FY can precede $minY
        if (aero_biennial_covered($y, $fBi, $lastYr)) { $filingYears[$y] = ['st' => 'covered']; continue; }
        if ($y < $minY) { $filingYears[$y] = ['st' => 'na']; continue; }
        $fyEnd = date('Y-m-d', mktime(0, 0, 0, $fm, $fd, $y));
        if ($dl9($fyEnd) >= time()) $filingYears[$y] = ['st' => 'pending'];
        else $filingYears[$y] = ['st' => $likely ? 'missing' : 'unverified'];
    }

    // Levels 2–7 — latest active audit only (flags, QC dollars, repeat lineage)
    $latestYear = $reps ? max($reps) : null;
    $levels = [1 => $missing, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0];
    $qcDollars = 0; $qcCount = 0; $totalF = 0;
    $req = []; $reqSev = [];   // per requirement letter: finding count + severity-flag breakdown
    $aging = [2 => 0, 3 => 0, 4 => 0];   // repeat-chain depth buckets (4 = 4+ years)
    $latestRid = null; $prevRid = null; $prevYear = null; $prevTotal = 0; $prevLevels = null;
    if ($latestYear !== null) {
        $latestRid = array_search($latestYear, $reps, true);
        foreach ($reps as $rid => $y) {                  // the audit immediately before the latest
            if ($y < $latestYear && ($prevYear === null || $y > $prevYear)) { $prevYear = $y; $prevRid = $rid; }
        }
        $refYear = []; $prior = []; $isRep = []; $rep = [];
        foreach ($fnd as $fd) {
            $refYear[$fd['ref']] = (int) $fd['yr'];
            $r = ((int) $fd['rep']) === 1;
            $isRep[$fd['ref']] = $r;
            $rep[$fd['ref']]   = $r ? 1 : 0;
            if ($r) {                                                          // hops only continue through repeats
                $prior[$fd['ref']] = aero_first_prior($fd['pr']);
            }
        }
        // one shared recurrence kernel (lib/Lineage.php) — same walk the scorer/repeat/grantee use
        $maps = ['refYear' => $refYear, 'prior' => $prior, 'rep' => $rep];
        foreach ($fnd as $fd) {
            if ($fd['report_id'] === $prevRid) $prevTotal++;
            if ($fd['report_id'] !== $latestRid) continue;
            $totalF++;
            if (preg_match_all('/[A-Z]/', (string) $fd['ty'], $m)) {
                foreach (array_unique($m[0]) as $ch) {
                    $req[$ch] = ($req[$ch] ?? 0) + 1;
                    if (!isset($reqSev[$ch])) $reqSev[$ch] = ['mw' => 0, 'sd' => 0, 'mo' => 0, 'rp' => 0, 'qc' => 0];
                    if ((int) $fd['mw'] === 1) $reqSev[$ch]['mw']++;
                    if ((int) $fd['sd'] === 1) $reqSev[$ch]['sd']++;
                    if ((int) $fd['mo'] === 1) $reqSev[$ch]['mo']++;
                    if ((int) $fd['rep'] === 1) $reqSev[$ch]['rp']++;
                    if ((int) $fd['qcf'] === 1) $reqSev[$ch]['qc']++;
                }
            }
            if ((int) $fd['mo'] === 1) $levels[2]++;
            if ((int) $fd['mw'] === 1) $levels[3]++;
            if (in_array($fd['qc_basis'], $QC_TRUSTED, true) && (int) $fd['qc_amount'] > 0) {
                $qcDollars += (int) $fd['qc_amount']; $qcCount++;
                $levels[4]++;   // any extracted questioned costs (no per-finding $ threshold)
            }
            if (!$isRep[$fd['ref']]) { $levels[7]++; continue; }
            $depth = Lineage::walk($fd['ref'], $maps)['traced_depth'];
            $aging[min($depth, 4)]++;
            if ($depth >= 3) $levels[5]++;
            else $levels[6]++;
        }

        // the PRIOR audit's most severe level (for the level-migration matrix): same
        // rules evaluated as of that audit — lineage capped at its year, L1 = years up
        // to that audit that are STILL not filed today (late-since filings don't count)
        if ($prevRid !== null) {
            $pl = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0];
            foreach ($missingYrs as $my) {
                if ($my <= $prevYear) $pl[1]++;
            }
            foreach ($fnd as $fd) {
                if ($fd['report_id'] !== $prevRid) continue;
                if ((int) $fd['mo'] === 1) $pl[2]++;
                if ((int) $fd['mw'] === 1) $pl[3]++;
                if (in_array($fd['qc_basis'], $QC_TRUSTED, true) && (int) $fd['qc_amount'] > 0) $pl[4]++;
                if (((int) $fd['rep']) !== 1) { $pl[7]++; continue; }
                // as-of the prior audit: count only chain years <= $prevYear (kernel asOfYear arg)
                if (Lineage::walk($fd['ref'], $maps, $prevYear)['traced_depth'] >= 3) $pl[5]++;
                else $pl[6]++;
            }
            $prevLevels = $pl;
        }
    }

    $top = null;
    foreach ($levels as $lv => $n) {
        if ($n > 0) { $top = $lv; break; }
    }
    // momentum: composite-score change between the two most recent scored years
    $delta = null;
    if (isset($scores[$uei]['trend'])) {
        $tr = json_decode((string) $scores[$uei]['trend'], true) ?: [];
        $nTr = count($tr);
        if ($nTr >= 2) $delta = round((float) $tr[$nTr - 1]['composite'] - (float) $tr[$nTr - 2]['composite'], 1);
    }
    $sam = $samReg[$uei] ?? null;
    $rows[] = [
        'state'        => $g['state'],
        'label'        => $g['label'],
        'uei'          => $uei,
        'latest_year'  => $latestYear,
        'findings'     => $totalF,
        'levels'       => $levels,
        'top_level'    => $top,
        'delinquent'   => ['late' => $late, 'missing' => $missing, 'unverified' => $unverified],
        'filing_years' => $filingYears,
        'req'          => $req,
        'qc'           => ['dollars' => $qcDollars, 'count' => $qcCount, 'significant' => $levels[4] > 0],
        'programs'     => [],   // filled from the per-report rollup below
        'federal'      => isset($scores[$uei]['federal_latest']) ? (float) $scores[$uei]['federal_latest'] : null,
        'auditor'      => $latestRid !== null ? ($repFirm[$latestRid] ?? null) : null,
        // remediation flow: the prior audit's findings vs how many of the latest are
        // repeats (L5+L6) / new (L7) — the dashboard's Sankey sums these across the scope
        'flow'         => $prevYear !== null
            ? ['prev_year' => $prevYear, 'prev_findings' => $prevTotal, 'repeated' => $levels[5] + $levels[6], 'new' => $levels[7]]
            : null,
        'score'        => isset($scores[$uei]) ? round((float) $scores[$uei]['composite_score'], 1) : null,
        'score_delta'  => $delta,
        'tier'         => $scores[$uei]['tier'] ?? null,
        'prev_levels'  => $prevLevels,   // the prior audit's level counts (null = no prior audit)
        'req_sev'      => $reqSev,
        'aging'        => $aging,
        'flags'        => ['sam_status' => $sam['status'] ?? null, 'sam_expires' => $sam['expires'] ?? null, 'exclusions' => $excl[$uei] ?? 0],
    ];
    if ($latestRid !== null) { $latestRids[] = $latestRid; $ridRow[$latestRid] = count($rows) - 1; }
}

// program rollup over the latest reports' finding↔award links (a finding can touch
// several awards/programs). Grouped per report so each ROW carries its own breakdown
// (the dashboard's state focus filters client-side); scope totals aggregate.
$programs = [];
if ($latestRids) {
    $rin = implode(',', array_fill(0, count($latestRids), '?'));
    $st = $pdo->prepare(
        "SELECT fwa.report_id rid, fa.aln, COALESCE(MAX(al.title), MAX(fa.federal_program_name)) name,
                COUNT(DISTINCT fwa.reference_number) findings
         FROM fac_finding_awards fwa
         JOIN fac_federal_awards fa ON fa.report_id = fwa.report_id AND fa.award_reference = fwa.award_reference
         LEFT JOIN assistance_listing al ON al.assistance_listing_id = fa.aln
         WHERE fwa.report_id IN ($rin) AND fa.aln IS NOT NULL
         GROUP BY fwa.report_id, fa.aln"
    );
    $st->execute($latestRids);
    $pAgg = [];
    foreach ($st as $r) {
        $name = $r['name'] !== null ? ucwords(strtolower((string) $r['name'])) : $r['aln'];
        $n = (int) $r['findings'];
        if (isset($ridRow[$r['rid']])) $rows[$ridRow[$r['rid']]]['programs'][] = ['aln' => $r['aln'], 'name' => $name, 'findings' => $n];
        if (!isset($pAgg[$r['aln']])) $pAgg[$r['aln']] = ['aln' => $r['aln'], 'name' => $name, 'findings' => 0, 'entities' => 0];
        $pAgg[$r['aln']]['findings'] += $n;
        $pAgg[$r['aln']]['entities']++;
    }
    usort($pAgg, fn ($a, $b) => $b['findings'] <=> $a['findings']);
    $programs = array_slice(array_values($pAgg), 0, 15);
    foreach ($rows as &$rr) {
        usort($rr['programs'], fn ($a, $b) => $b['findings'] <=> $a['findings']);
        $rr['programs_total'] = count($rr['programs']);
        $rr['programs'] = array_slice($rr['programs'], 0, 12);
    }
    unset($rr);
}

// most severe first (lowest triggered level, then composite score), clean entities last.
// MUST run after the rollup above — it attributes per-report data by row index.
usort($rows, fn ($a, $b) => [$a['top_level'] ?? 99, -($a['score'] ?? 0)] <=> [$b['top_level'] ?? 99, -($b['score'] ?? 0)]);

$out = ['states' => $rows, 'programs' => $programs,
        'type' => $type, 'total' => $total ?? count($rows), 'capped' => $capped, 'generated_at' => date('c')];
@mkdir(dirname($cacheFile), 0775, true);
@file_put_contents($cacheFile, json_encode($out));
json_out($out);
