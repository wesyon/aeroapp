<?php
declare(strict_types=1);
/**
 * GET /api/repeat[?type=stategov|local|...][&state=XX] — repeat-finding analytics for a
 * scope, one aggregate per call. Companion to /api/evaluation: same entity scoping
 * (state_uei registry for governments; riskiest 500 by composite for other types), same
 * canonical-UEI union (UEI successions merged so a chain crosses the switch), same 6h
 * disk cache. Where /api/evaluation emits one ROW per entity with its L1-L7 counts, this
 * focuses entirely on the L5/6/7 repeat dimension: recurrence depth, lineage
 * traceability (how often the FAC prior-ref data lets us trace vs. falls back to the
 * flag), the most persistent recipients, and what recurs / where.
 *
 * The repeat set analysed is each entity's MOST RECENT active audit — the same findings
 * that drive its current Level 5/6/7 split — so the totals reconcile with the Evaluation
 * dashboard. Lineage depth is walked over the entity's full in-window history.
 */

$type  = q_str('type') ?? 'stategov';
$state = q_str('state');
if ($state !== null && !preg_match('/^[A-Za-z]{2}$/', $state)) $state = null;
$state = $state !== null ? strtoupper($state) : null;

$ENTITY_TYPES = ['local', 'non-profit', 'higher-ed', 'state', 'tribal', 'for-profit', 'foreign', 'unknown'];
$REGISTRY_TYPES = ['stategov', 'territory'];
if (!in_array($type, $REGISTRY_TYPES, true) && !in_array($type, $ENTITY_TYPES, true)) {
    json_out(['error' => 'unknown type', 'allowed' => array_merge($REGISTRY_TYPES, $ENTITY_TYPES)], 400);
}

// cache key carries a schema version: bump CACHE_VER whenever the response shape changes
// so old caches are simply not read (rather than mixing old/new fields in the UI).
require_once dirname(__DIR__) . '/lib/Lineage.php';

$CACHE_VER = 'v5';   // v5 = trace split: before_window (trace ends) vs unresolved_ref (chain break)
$cacheFile = dirname(__DIR__) . "/cache/repeat_{$CACHE_VER}_" . $type . '_' . ($state ?? 'all') . '.json';
$fresh = isset($_GET['fresh']) && is_local_request();
if (!$fresh && is_file($cacheFile) && (time() - filemtime($cacheFile)) < 21600) {
    json_out(json_decode((string) file_get_contents($cacheFile), true));
}

$CAP = 500;

// ---- candidate groups (identical scoping to /api/evaluation) ---------------------------
$groups = []; $capped = false; $total = null;
$sgUeis = [];
$TERRITORIES = ['AS', 'GU', 'MP', 'PR', 'VI'];
foreach ($pdo->query("SELECT state_code, label, ueis FROM state_uei") as $r) {
    $set = array_values(array_filter(array_map('trim', preg_split('/\R+/', (string) $r['ueis']) ?: [])));
    array_push($sgUeis, ...$set);
    if (!in_array($type, $REGISTRY_TYPES, true)) continue;
    $isTerr = in_array($r['state_code'], $TERRITORIES, true);
    if ($type === 'stategov' ? $isTerr : !$isTerr) continue;
    if ($state !== null && $r['state_code'] !== $state) continue;
    if ($set) $groups[$r['state_code']] = ['state' => $r['state_code'], 'label' => $r['label'], 'ueis' => $set];
}
if (!in_array($type, $REGISTRY_TYPES, true)) {
    // universe from the entity directory (score-independent); aero_score (s) joined only to rank
    $w = ['e.entity_type = ?', 'e.latest_audit_year IS NOT NULL']; $p = [$type];
    if ($type === 'state' && $sgUeis) {
        $w[] = 'e.uei NOT IN (' . implode(',', array_fill(0, count($sgUeis), '?')) . ')';
        array_push($p, ...$sgUeis);
    }
    if ($state !== null) { $w[] = 'e.state = ?'; $p[] = $state; }
    $where = implode(' AND ', $w);
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM entity e WHERE $where");
    $cnt->execute($p);
    $total = (int) $cnt->fetchColumn();
    $capped = $total > $CAP;
    $st = $pdo->prepare("SELECT e.uei, e.display_name recipient_name, e.state
                         FROM entity e LEFT JOIN aero_score s ON s.uei = e.uei WHERE $where
                         ORDER BY s.composite_score DESC, e.federal_latest DESC LIMIT $CAP");
    $st->execute($p);
    foreach ($st as $r) $groups[$r['uei']] = ['state' => $r['state'], 'label' => $r['recipient_name'] ?: $r['uei'], 'ueis' => [$r['uei']]];
}

$all = $groups ? array_merge(...array_column($groups, 'ueis')) : [];
if (!$all) {
    json_out(['type' => $type, 'state' => $state, 'total' => 0, 'capped' => false, 'generated_at' => date('c'),
              'overview' => null, 'persistent' => [], 'by_requirement' => [], 'by_program' => [], 'findings' => []]);
}
$in = implode(',', array_fill(0, count($all), '?'));

// ---- active reports + findings (active only) -------------------------------------------
$st = $pdo->prepare("SELECT auditee_uei uei, report_id, audit_year yr, audit_period_covered period FROM fac_general WHERE auditee_uei IN ($in) AND is_active = 1");
$st->execute($all);
$activeRep = [];   // uei => report_id => year
$repBiennial = []; // report_id => true when audited biennially (2 CFR 200.504 — prior audit is 2 yrs back)
foreach ($st as $r) {
    $activeRep[$r['uei']][$r['report_id']] = (int) $r['yr'];
    if ($r['period'] === 'biennial') $repBiennial[$r['report_id']] = true;
}

$st = $pdo->prepare(
    "SELECT f.auditee_uei uei, f.report_id, f.reference_number ref, f.audit_year yr,
            f.is_repeat_finding rep, f.prior_finding_ref_numbers pr, f.type_requirement ty,
            f.is_material_weakness mw, f.is_significant_deficiency sd, f.is_modified_opinion mo,
            f.is_questioned_costs qcf
     FROM fac_findings f JOIN fac_general g ON g.report_id = f.report_id AND g.is_active = 1
     WHERE f.auditee_uei IN ($in)"
);
$st->execute($all);
$findingsByUei = [];
foreach ($st as $r) $findingsByUei[$r['uei']][] = $r;

// ---- accumulators ----------------------------------------------------------------------
$overview = [
    'entities_in_scope' => 0, 'entities_with_repeats' => 0,
    'latest_findings_total' => 0, 'latest_repeats' => 0, 'latest_new' => 0,
    'chronic_findings' => 0,    // repeats running the full visible window (traced depth >= 4)
    'gap_findings' => 0,        // repeats that recur with a gap (skip a filed audit year) — lapse-and-return
    'documented_findings' => 0, // repeats whose documented span exceeds what we can trace (auditor enumerated pre-window)
    // documented-depth buckets of latest-audit repeats (distinct years the recurrence spans)
    'depth_dist' => ['2' => 0, '3' => 0, '4-6' => 0, '7-9' => 0, '10+' => 0],
    // trace splits the first prior: ok = resolves to an earlier audit; before_window = prior
    // predates our records (trace ends, a coverage limit); unresolved_ref / not_earlier =
    // a prior we should be able to find but can't (genuine chain break); no_prior = none cited
    'trace' => ['ok' => 0, 'before_window' => 0, 'unresolved_ref' => 0, 'not_earlier' => 0, 'no_prior' => 0],
    'biennial_entities' => 0, 'biennial_repeats' => 0,   // biennial filers: priors are 2 yrs back, so often pre-window
    'long_running' => 0,                                 // repeats documented to span 10+ years
];
$persistent = [];
$byReq = [];                 // requirement letter => repeat count
$findingsOut = [];
$repeatAwardKeys = [];       // "report_id|ref" of latest-audit repeats (for the program rollup)

$REQ_LABEL = [
    'A' => 'Activities Allowed', 'B' => 'Allowable Costs', 'C' => 'Cash Management', 'E' => 'Eligibility',
    'F' => 'Equipment & Real Property', 'G' => 'Matching / Level of Effort', 'H' => 'Period of Performance',
    'I' => 'Procurement', 'J' => 'Program Income', 'L' => 'Reporting', 'M' => 'Subrecipient Monitoring',
    'N' => 'Special Tests & Provisions', 'P' => 'Other / uncategorized', 'D' => 'Davis-Bacon (legacy)',
    'K' => 'Real Property Acquisition (legacy)',
];

foreach ($groups as $g) {
    // choose the current UEI = most recent active year (same tie-break basis as evaluation)
    $uei = null; $bestYr = -1;
    foreach ($g['ueis'] as $u) {
        $ly = isset($activeRep[$u]) && $activeRep[$u] ? max($activeRep[$u]) : -1;
        if ($ly > $bestYr) { $bestYr = $ly; $uei = $u; }
    }
    // merge the group's active reports + findings
    $reps = []; $fnd = [];
    foreach ($g['ueis'] as $u) {
        $reps += $activeRep[$u] ?? [];
        if (isset($findingsByUei[$u])) $fnd = array_merge($fnd, $findingsByUei[$u]);
    }
    if (!$reps) continue;
    $overview['entities_in_scope']++;
    $latestYear = max($reps);
    $latestRid = array_search($latestYear, $reps, true);
    $biennial = isset($repBiennial[$latestRid]);   // latest audit is biennial -> prior audit is FY(latest-2)

    // lineage maps across the group's full in-window history (inputs to the shared kernel)
    $refYear = []; $prior = []; $priorYears = []; $rep = [];
    foreach ($fnd as $f) {
        $refYear[$f['ref']] = (int) $f['yr'];
        $rep[$f['ref']]     = (int) $f['rep'];
        if ((int) $f['rep'] === 1) {
            $prior[$f['ref']] = aero_first_prior($f['pr']);        // first ref, for hopping loaded findings
            $priorYears[$f['ref']] = aero_prior_years($f['pr']);   // every year the auditor named in the list
        }
    }
    $bucketOf = fn (int $d): string => $d >= 10 ? '10+' : ($d >= 7 ? '7-9' : ($d >= 4 ? '4-6' : (string) $d));
    $filedYrs = array_flip(array_values($reps));   // audit years the entity actually filed (active reports) — keys = years
    $windowStart = min($reps);                     // earliest audit year we hold for this entity (coverage floor)
    // The traced/documented depth walk and the trace category come from the one shared
    // kernel (lib/Lineage.php) — boundary credit, cycle guard, hop ceiling all defined there.
    $maps = ['refYear' => $refYear, 'prior' => $prior, 'rep' => $rep,
             'priorYears' => $priorYears, 'windowStart' => $windowStart];

    $entRepeats = 0; $entChronic = 0; $entGaps = 0; $entDeepest = 0; $entDeepTracedDepth = 0; $entDeepRef = null; $entDeepTraced = true;
    foreach ($fnd as $f) {
        if ($f['report_id'] !== $latestRid) continue;
        $overview['latest_findings_total']++;
        if ((int) $f['rep'] !== 1) { $overview['latest_new']++; continue; }
        // ---- a latest-audit repeat finding ----
        $overview['latest_repeats']++;
        $entRepeats++;
        $ref = $f['ref']; $yr = (int) $f['yr'];
        $lin = Lineage::walk($ref, $maps);
        $depth = $lin['traced_depth'];        // traced (reconstructed from loaded findings)
        $docDepth = $lin['documented_depth']; // documented (incl. years the auditor enumerated)
        $overview['depth_dist'][$bucketOf($docDepth)]++;
        if ($docDepth >= 10) $overview['long_running']++;

        // chronic = runs the full visible window (traced depth >= 4); the breadth-at-depth
        // signal that distinguishes entities once the single deepest chain saturates at the ceiling
        $chronic = $depth >= 4;
        if ($chronic) { $overview['chronic_findings']++; $entChronic++; }
        if ($docDepth > $depth) $overview['documented_findings']++;
        // gap = the chain skips an audit year the entity actually filed within the window (lapse-and-return)
        $ly = $lin['loaded_years']; $gap = false;
        if (count($ly) >= 2) {
            $lyset = array_flip($ly);
            for ($y = min($ly) + 1, $hi = max($ly); $y < $hi; $y++) {
                if (isset($filedYrs[$y]) && !isset($lyset[$y])) { $gap = true; break; }
            }
        }
        if ($gap) { $overview['gap_findings']++; $entGaps++; }

        // traceability of THIS finding's own first prior (the per-finding trace category):
        // ok | before_window (trace ends) | unresolved_ref (chain breaks) | not_earlier | no_prior
        $tr = Lineage::firstHopTrace($ref, $maps);
        $cat = $tr['cat']; $traced = $tr['traced'];
        $overview['trace'][$cat]++;

        // rank by documented depth; remember whether the deepest is fully traced or
        // extends only via the auditor's reference list (documented beyond loaded data)
        if ($docDepth > $entDeepest) {
            $entDeepest = $docDepth; $entDeepTracedDepth = $depth; $entDeepRef = $ref; $entDeepTraced = $traced;
        }

        foreach (array_unique(preg_match_all('/[A-Z]/', (string) $f['ty'], $m) ? $m[0] : []) as $ch) {
            $byReq[$ch] = ($byReq[$ch] ?? 0) + 1;
        }
        $repeatAwardKeys[$f['report_id'] . '|' . $ref] = true;

        $findingsOut[] = [
            'uei' => $uei, 'label' => $g['label'], 'state' => $g['state'],
            'year' => $yr, 'ref' => $ref, 'report_id' => $f['report_id'],
            'type' => $f['ty'], 'depth' => $docDepth, 'traced_depth' => $depth,
            'documented' => $docDepth > $depth, 'traced' => $traced, 'trace' => $cat,
            'chronic' => $chronic, 'gap' => $gap,
            'biennial' => $biennial, 'prior' => aero_first_prior($f['pr']),
            'mw' => (int) $f['mw'], 'sd' => (int) $f['sd'], 'mo' => (int) $f['mo'], 'qc' => (int) $f['qcf'],
        ];
    }
    if ($entRepeats > 0) {
        $overview['entities_with_repeats']++;
        if ($biennial) { $overview['biennial_entities']++; $overview['biennial_repeats'] += $entRepeats; }
        $persistent[] = [
            'uei' => $uei, 'label' => $g['label'], 'state' => $g['state'], 'latest_year' => $latestYear,
            'repeats' => $entRepeats, 'chronic' => $entChronic, 'gaps' => $entGaps,
            'deepest_chain' => $entDeepest, 'deepest_traced_depth' => $entDeepTracedDepth,
            'deepest_ref' => $entDeepRef, 'deepest_documented' => $entDeepest > $entDeepTracedDepth,
            'deepest_traced' => $entDeepTraced, 'biennial' => $biennial,
        ];
    }
}

// ---- program rollup: repeat findings per ALN over the latest audits ---------------------
$byProgram = [];
if ($repeatAwardKeys) {
    $latestRids = array_values(array_unique(array_map(fn ($k) => explode('|', $k)[0], array_keys($repeatAwardKeys))));
    $rin = implode(',', array_fill(0, count($latestRids), '?'));
    $st = $pdo->prepare(
        "SELECT fwa.report_id rid, fwa.reference_number ref, fa.aln,
                COALESCE(al.title, fa.federal_program_name) name, g.auditee_uei uei
         FROM fac_finding_awards fwa
         JOIN fac_federal_awards fa ON fa.report_id = fwa.report_id AND fa.award_reference = fwa.award_reference
         JOIN fac_general g ON g.report_id = fwa.report_id
         LEFT JOIN assistance_listing al ON al.assistance_listing_id = fa.aln
         WHERE fwa.report_id IN ($rin) AND fa.aln IS NOT NULL"
    );
    $st->execute($latestRids);
    $pAgg = [];
    foreach ($st as $r) {
        if (!isset($repeatAwardKeys[$r['rid'] . '|' . $r['ref']])) continue;   // only repeat findings
        $aln = $r['aln'];
        if (!isset($pAgg[$aln])) $pAgg[$aln] = ['aln' => $aln, 'name' => $r['name'] !== null ? ucwords(strtolower((string) $r['name'])) : $aln, 'refs' => [], 'ueis' => []];
        $pAgg[$aln]['refs'][$r['rid'] . '|' . $r['ref']] = true;
        $pAgg[$aln]['ueis'][$r['uei']] = true;
    }
    foreach ($pAgg as $a) $byProgram[] = ['aln' => $a['aln'], 'name' => $a['name'], 'repeats' => count($a['refs']), 'entities' => count($a['ueis'])];
    usort($byProgram, fn ($a, $b) => $b['repeats'] <=> $a['repeats']);
    $byProgram = array_slice($byProgram, 0, 15);
}

// ---- finalize ordered outputs ----------------------------------------------------------
// rank by breadth-at-depth first (chronic full-window findings) — the discriminator that
// survives the window ceiling — then by the single deepest documented chain, then raw repeats
usort($persistent, fn ($a, $b) => [$b['chronic'], $b['deepest_chain'], $b['repeats']] <=> [$a['chronic'], $a['deepest_chain'], $a['repeats']]);
$persistent = array_slice($persistent, 0, 100);

$byRequirement = [];
foreach ($byReq as $code => $n) $byRequirement[] = ['code' => $code, 'label' => $REQ_LABEL[$code] ?? $code, 'repeats' => $n];
usort($byRequirement, fn ($a, $b) => $b['repeats'] <=> $a['repeats']);

// finding explorer: deepest / least-traceable first, bounded for transport
usort($findingsOut, fn ($a, $b) => [$b['depth'], (int) !$b['traced']] <=> [$a['depth'], (int) !$a['traced']]);
$findingsCapped = count($findingsOut) > 2000;
$findingsOut = array_slice($findingsOut, 0, 2000);

$out = [
    'type' => $type, 'state' => $state, 'total' => $total ?? $overview['entities_in_scope'],
    'capped' => $capped, 'findings_capped' => $findingsCapped, 'generated_at' => date('c'),
    'overview' => $overview, 'persistent' => $persistent,
    'by_requirement' => $byRequirement, 'by_program' => $byProgram, 'findings' => $findingsOut,
];
@mkdir(dirname($cacheFile), 0775, true);
@file_put_contents($cacheFile, json_encode($out));
json_out($out);
