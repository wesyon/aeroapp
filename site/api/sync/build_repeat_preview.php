<?php
declare(strict_types=1);

/**
 * Build repeat_preview — one row per REPEAT finding on each recipient's most recent active
 * Single Audit, with its recurrence depth, pattern, and lineage traceability precomputed.
 * Feeds the Repeat Findings dashboard (api/routes/repeats.php).
 *
 * Same push pattern as delinquency_preview: built locally over the FULL population
 * (the old /api/repeat route capped non-registry scopes at the 500 riskiest entities and
 * cached per scope — this table replaces both), pushed to prod via deploy.ps1 -PushTable
 * repeat_preview. Prod never rebuilds it.
 *
 * The depth walk and trace categories come from the one shared kernel (lib/Lineage.php) —
 * the same rule the Evaluation's L5/6/7 split uses, so the two surfaces reconcile.
 * UEI successions (state_uei registry) are merged so a chain crosses the switch; the row
 * is attributed to the group's canonical (most recently active) UEI.
 *
 *   php api/sync/build_repeat_preview.php
 */

$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
require $root . '/lib/Rules.php';
require $root . '/lib/UeiGroups.php';
require $root . '/lib/Lineage.php';
Env::load(dirname($root, 2) . '/.env');
Env::load(dirname($root) . '/.env');
$pdo = Db::connect();
$t0 = microtime(true);

$pdo->exec("DROP TABLE IF EXISTS repeat_preview");
$pdo->exec("CREATE TABLE repeat_preview (
  uei CHAR(12) NOT NULL,                -- canonical UEI (succession groups merged)
  label VARCHAR(255) NOT NULL,          -- baked in: prod's entity table is HHS-pruned
  state CHAR(2) NULL,
  entity_type VARCHAR(20) NULL,         -- 'stategov' still resolves via the registry at query time
  fy SMALLINT NOT NULL,                 -- the latest audit's year (the audit the finding sits on)
  report_id VARCHAR(40) NOT NULL,
  ref VARCHAR(20) NOT NULL,
  req VARCHAR(40) NULL,                 -- 2 CFR 200 requirement letters
  depth_doc TINYINT NOT NULL,           -- documented span (loaded chain + auditor-enumerated years)
  depth_traced TINYINT NOT NULL,        -- reconstructed from loaded findings only
  chronic TINYINT NOT NULL DEFAULT 0,   -- runs the full visible window (traced >= 4)
  gap TINYINT NOT NULL DEFAULT 0,       -- lapse-and-return (skips a filed audit year)
  documented TINYINT NOT NULL DEFAULT 0,-- doc span exceeds traced (auditor reached pre-window)
  traced TINYINT NOT NULL DEFAULT 0,    -- first prior resolves to an earlier loaded audit
  trace_cat VARCHAR(16) NOT NULL,       -- ok|before_window|unresolved_ref|not_earlier|no_prior
  biennial TINYINT NOT NULL DEFAULT 0,  -- 2 CFR 200.504 filer: prior audit is 2 yrs back
  mw TINYINT NOT NULL DEFAULT 0,
  sd TINYINT NOT NULL DEFAULT 0,
  mo TINYINT NOT NULL DEFAULT 0,
  qcf TINYINT NOT NULL DEFAULT 0,
  qc_amount BIGINT NULL,                -- stated questioned costs (fac_finding_extract)
  prior_ref VARCHAR(255) NULL,
  PRIMARY KEY (report_id, ref),
  KEY idx_rp_uei (uei), KEY idx_rp_depth (depth_doc),
  KEY idx_rp_state (state), KEY idx_rp_type (entity_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ---- candidate groups: every recipient with a repeat on an ACTIVE report ----------------
$grp = aero_uei_groups($pdo);
$canon = $grp['canon'];      // winner => [member ueis] (multi-UEI registry governments)
$retired = $grp['retired'];  // member => winner

$cands = $pdo->query(
    "SELECT DISTINCT f.auditee_uei FROM fac_findings f
     JOIN fac_general g ON g.report_id = f.report_id
     WHERE g.is_active = 1 AND f.is_repeat_finding = 1"
)->fetchAll(PDO::FETCH_COLUMN);

// collapse candidates into groups: a retired member pulls in its whole succession group
$groups = [];                // canonical => [member ueis]
foreach ($cands as $u) {
    $u = strtoupper($u);
    $win = $retired[$u] ?? (isset($canon[$u]) ? $u : null);
    if ($win !== null) $groups[$win] = $canon[$win];
    else $groups[$u] = [$u];
}
echo count($cands) . " candidate UEIs -> " . count($groups) . " groups\n";

// ---- bulk-load inputs for all groups -----------------------------------------------------
$all = array_values(array_unique(array_merge(...array_values($groups))));
$in = implode(',', array_fill(0, count($all), '?'));

$st = $pdo->prepare("SELECT auditee_uei uei, report_id, audit_year yr, audit_period_covered period
                     FROM fac_general WHERE auditee_uei IN ($in) AND is_active = 1");
$st->execute($all);
$activeRep = []; $repBiennial = [];
foreach ($st as $r) {
    $activeRep[$r['uei']][$r['report_id']] = (int) $r['yr'];
    if ($r['period'] === 'biennial') $repBiennial[$r['report_id']] = true;
}

// full active finding history per UEI (the walk needs non-repeats too, as chain hops);
// qc_amount rides along via the covering index (see the findings dashboard migration)
$st = $pdo->prepare(
    "SELECT f.auditee_uei uei, f.report_id, f.reference_number ref, f.audit_year yr,
            f.is_repeat_finding rep, f.prior_finding_ref_numbers pr, f.type_requirement ty,
            f.is_material_weakness mw, f.is_significant_deficiency sd, f.is_modified_opinion mo,
            f.is_questioned_costs qcf, x.qc_amount
     FROM fac_findings f
     JOIN fac_general g ON g.report_id = f.report_id AND g.is_active = 1
     LEFT JOIN fac_finding_extract x FORCE INDEX (idx_fext_join_qc)
       ON x.report_id = f.report_id AND x.finding_ref_number = f.reference_number
     WHERE f.auditee_uei IN ($in)"
);
$st->execute($all);
$findingsByUei = [];
foreach ($st as $r) $findingsByUei[$r['uei']][] = $r;

// display fields, baked in (entity is the local source of truth; state_uei labels win for
// merged governments so the row reads "State of X", not the winning sub-UEI's SAM name)
$entity = [];
$st = $pdo->prepare("SELECT uei, display_name, state, entity_type FROM entity WHERE uei IN ($in)");
$st->execute($all);
foreach ($st as $r) $entity[$r['uei']] = $r;
$sgLabel = [];
foreach ($pdo->query("SELECT state_code, label, ueis FROM state_uei") as $r) {
    foreach (preg_split('/[\s,]+/', trim((string) $r['ueis']), -1, PREG_SPLIT_NO_EMPTY) as $u) {
        $sgLabel[strtoupper($u)] = ['label' => $r['label'], 'state' => $r['state_code']];
    }
}

// ---- walk each group's latest-audit repeats ----------------------------------------------
$ins = $pdo->prepare(
    "INSERT INTO repeat_preview
     (uei, label, state, entity_type, fy, report_id, ref, req, depth_doc, depth_traced,
      chronic, gap, documented, traced, trace_cat, biennial, mw, sd, mo, qcf, qc_amount, prior_ref)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
);
$rows = 0; $ents = 0;
$pdo->beginTransaction();
foreach ($groups as $winner => $members) {
    // canonical UEI = most recent active year among members (matches evaluation/repeat legacy)
    $uei = null; $bestYr = -1;
    foreach ($members as $u) {
        $ly = isset($activeRep[$u]) && $activeRep[$u] ? max($activeRep[$u]) : -1;
        if ($ly > $bestYr) { $bestYr = $ly; $uei = $u; }
    }
    if ($uei === null) continue;
    $reps = []; $fnd = [];
    foreach ($members as $u) {
        $reps += $activeRep[$u] ?? [];
        if (isset($findingsByUei[$u])) $fnd = array_merge($fnd, $findingsByUei[$u]);
    }
    if (!$reps) continue;
    $latestYear = max($reps);
    $latestRid = array_search($latestYear, $reps, true);
    $biennial = isset($repBiennial[$latestRid]) ? 1 : 0;

    $e = $entity[$uei] ?? null;
    $sg = $sgLabel[$uei] ?? null;
    $label = $sg['label'] ?? ($e['display_name'] ?? null) ?: $uei;
    $state = $sg['state'] ?? ($e['state'] ?? null);
    $etype = $e['entity_type'] ?? null;

    // lineage maps over the group's full in-window history (kernel input contract)
    $refYear = []; $prior = []; $priorYears = []; $rep = [];
    foreach ($fnd as $f) {
        $refYear[$f['ref']] = (int) $f['yr'];
        $rep[$f['ref']] = (int) $f['rep'];
        if ((int) $f['rep'] === 1) {
            $prior[$f['ref']] = aero_first_prior($f['pr']);
            $priorYears[$f['ref']] = aero_prior_years($f['pr']);
        }
    }
    $filedYrs = array_flip(array_values($reps));
    $maps = ['refYear' => $refYear, 'prior' => $prior, 'rep' => $rep,
             'priorYears' => $priorYears, 'windowStart' => min($reps)];

    $wrote = false;
    foreach ($fnd as $f) {
        if ($f['report_id'] !== $latestRid || (int) $f['rep'] !== 1) continue;
        $lin = Lineage::walk($f['ref'], $maps);
        $depth = $lin['traced_depth'];
        $docDepth = $lin['documented_depth'];
        $chronic = $depth >= 4 ? 1 : 0;
        $ly = $lin['loaded_years']; $gap = 0;
        if (count($ly) >= 2) {
            $lyset = array_flip($ly);
            for ($y = min($ly) + 1, $hi = max($ly); $y < $hi; $y++) {
                if (isset($filedYrs[$y]) && !isset($lyset[$y])) { $gap = 1; break; }
            }
        }
        $tr = Lineage::firstHopTrace($f['ref'], $maps);
        $ins->execute([
            $uei, $label, $state, $etype, $latestYear, $f['report_id'], $f['ref'],
            $f['ty'] ?: null, $docDepth, $depth, $chronic, $gap,
            $docDepth > $depth ? 1 : 0, $tr['traced'] ? 1 : 0, $tr['cat'], $biennial,
            (int) $f['mw'], (int) $f['sd'], (int) $f['mo'], (int) $f['qcf'],
            $f['qc_amount'] !== null ? (int) $f['qc_amount'] : null,
            aero_first_prior($f['pr']) ?: null,
        ]);
        $rows++; $wrote = true;
    }
    if ($wrote) $ents++;
}
$pdo->commit();

printf("repeat_preview: %d rows across %d recipients in %.1fs\n", $rows, $ents, microtime(true) - $t0);
