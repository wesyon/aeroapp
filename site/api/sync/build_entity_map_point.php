<?php
declare(strict_types=1);

/**
 * Build entity_map_point — one precomputed map dot per active, mappable entity, so the geographic
 * dot layer (/api/geo_points) is a fast indexed read rather than a ~4-6s per-call aggregation.
 *
 * Per entity it stores placement + the EXACT enforcement top_level (`lvl`, 1 most severe .. 7;
 * 0 = clean), computed the same way the Evaluation does:
 *   - placement: modal-ZIP centroid (most frequent ZIP across the entity's audits, typo-robust),
 *     falling back to the ZIP3-prefix centroid when the exact ZIP isn't a Census ZCTA (PO-box /
 *     government ZIPs — common for state agencies).
 *   - L2/L3/L4/L7: SQL flags off the latest audit (modified opinion / material weakness /
 *     questioned costs / any finding).
 *   - L1 (delinquent audits): the shared missing-year walk (lib/Rules.php aero_filing_status) —
 *     2 CFR 200.512 deadline + biennial coverage + the two-signal confirmation (award activity,
 *     else the federal >= $2M proxy), identical to /api/evaluation and /api/grantee.
 *   - L5/L6 (2+yr vs 1st-yr repeats): the lineage-depth walk (lib/Lineage.php) — depth >= 3 = L5.
 *   - UEI successions (state_uei): one dot per government, at its canonical UEI, with the group's
 *     filing history merged into its L1 — matching /api/evaluation and /api/recipients.
 *
 * Re-runnable (full rebuild). Run after FAC sync + the zip_centroid seed.
 *   php api/sync/build_entity_map_point.php
 */

$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
require $root . '/lib/Rules.php';      // aero_filing_status / aero_activity_confirmer / aero_first_prior
require $root . '/lib/UeiGroups.php';  // aero_uei_groups — collapse state_uei successions
require $root . '/lib/Lineage.php';    // repeat-depth kernel (canonical Evaluation semantics)
Env::load(dirname($root, 2) . '/.env');
Env::load(dirname($root) . '/.env');
$pdo = Db::connect();

$a = microtime(true);

$pdo->exec("DROP TABLE IF EXISTS entity_map_point");
$pdo->exec("CREATE TABLE entity_map_point (
  uei CHAR(12) NOT NULL, lat DECIMAL(9,6) NOT NULL, lng DECIMAL(9,6) NOT NULL,
  mw TINYINT NOT NULL DEFAULT 0, rp TINYINT NOT NULL DEFAULT 0, qc TINYINT NOT NULL DEFAULT 0,
  mo TINYINT NOT NULL DEFAULT 0, hf TINYINT NOT NULL DEFAULT 0, lvl TINYINT NOT NULL DEFAULT 0,
  PRIMARY KEY (uei), KEY idx_emp_lvl (lvl)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ZIP3-prefix centroids — used when an entity's exact ZIP isn't in the (ZCTA-based) zip_centroid.
$pdo->exec("DROP TEMPORARY TABLE IF EXISTS zip3_centroid");
$pdo->exec("CREATE TEMPORARY TABLE zip3_centroid (z3 CHAR(3) NOT NULL PRIMARY KEY, lat DECIMAL(9,6), lng DECIMAL(9,6))");
$pdo->exec("INSERT INTO zip3_centroid SELECT LEFT(zip,3), AVG(lat), AVG(lng) FROM zip_centroid GROUP BY LEFT(zip,3)");

// --- 1) placement + flags + provisional level (L2/L3/L4/L7; L1 and the L5/L6 split come below) ---
$pdo->exec("
  INSERT INTO entity_map_point (uei, lat, lng, mw, rp, qc, mo, hf, lvl)
  SELECT t.uei, t.lat, t.lng, t.mw, t.rp, t.qc, t.mo, t.hf,
         CASE WHEN t.mo=1 THEN 2 WHEN t.mw=1 THEN 3 WHEN t.qc=1 THEN 4
              WHEN t.rp=1 THEN 6 WHEN t.hf=1 THEN 7 ELSE 0 END
  FROM (
    SELECT g.auditee_uei AS uei, COALESCE(z.lat, z3.lat) AS lat, COALESCE(z.lng, z3.lng) AS lng,
           COALESCE(MAX(f.is_material_weakness), 0) AS mw,
           COALESCE(MAX(f.is_repeat_finding), 0)    AS rp,
           -- L4 = trusted extracted questioned costs (matches Evaluation), not the raw flag
           COALESCE(MAX(CASE WHEN x.qc_basis IN ('known','generic','flagged','inline') AND x.qc_amount > 0 THEN 1 ELSE 0 END), 0) AS qc,
           COALESCE(MAX(f.is_modified_opinion), 0)  AS mo,
           (COUNT(f.reference_number) > 0)          AS hf
    FROM fac_general g
    JOIN entity e ON e.uei = g.auditee_uei AND g.audit_year = e.latest_audit_year
    JOIN (
      SELECT auditee_uei, zip5 FROM (
        SELECT g2.auditee_uei, LEFT(g2.auditee_zip, 5) zip5,
               ROW_NUMBER() OVER (PARTITION BY g2.auditee_uei ORDER BY COUNT(*) DESC, MAX(g2.audit_year) DESC) rn
        FROM fac_general g2
        WHERE g2.is_active = 1 AND g2.auditee_zip IS NOT NULL AND g2.auditee_zip <> ''
        GROUP BY g2.auditee_uei, LEFT(g2.auditee_zip, 5)
      ) r WHERE r.rn = 1
    ) mz ON mz.auditee_uei = g.auditee_uei
    LEFT JOIN zip_centroid  z  ON z.zip  = mz.zip5
    LEFT JOIN zip3_centroid z3 ON z3.z3  = LEFT(mz.zip5, 3)
    LEFT JOIN fac_findings  f  ON f.report_id = g.report_id
    LEFT JOIN fac_finding_extract x ON x.report_id = f.report_id AND x.finding_ref_number = f.reference_number
    WHERE g.is_active = 1 AND (z.lat IS NOT NULL OR z3.lat IS NOT NULL)
      -- NOTE: deliberately NOT HHS-scoped. Local's evaluation shows the full FAC universe, so the
      -- dot layer must cover it too (a 2026-07-07 HHS filter here made local Inspectors show
      -- entities the map couldn't plot). Prod safety needs no build filter: geo_points requires
      -- e.latest_audit_year IS NOT NULL, and the entity directory NULLs identity for UEIs pruned
      -- from prod's HHS scope — so non-HHS rows that reach prod's table are invisible there anyway.
    GROUP BY g.auditee_uei, COALESCE(z.lat, z3.lat), COALESCE(z.lng, z3.lng)
  ) t
");

// --- 1b) UEI successions: one dot per government, at its CANONICAL UEI ---
// A crosswalk government (state_uei) that changed UEI has a directory row per member, and both
// were getting a dot — so a state appeared twice, and the RETIRED member read as a delinquent
// entity that "stopped filing" (latest 2022) while the government has filed every year since
// under its successor. That produced a false L1 dot for 9 of 19 member UEIs (AR/CO/DC/DE/NC/
// OH/SC/VI/WV) — an accusation of delinquency for changing UEI. /api/evaluation and
// /api/recipients both collapse the succession to the canonical member; this does the same,
// and $groupOf below merges the whole group's filing history into that member's L1 walk.
// Canonical = member with the latest active audit year, tie-break federal $ — lib/UeiGroups.php.
$grp = aero_uei_groups($pdo);
$groupOf = $grp['canon'];               // canonical uei => [member ueis] (multi-UEI groups only)
$retired = array_keys($grp['retired']); // non-canonical member ueis — must not carry their own dot

// Component MONEY family (fac_additional_ueis UNION entity_related_uei): a parent auditee's money
// lands under its component agencies' own UEIs even though it files one consolidated audit. The L1
// MONEY check rolls these up; FILINGS stay on the succession set. Without it, a component-heavy
// entity looks inactive and only the $2M proxy catches it (State of Nevada = $0 solo, $6.9B family).
$compOf = [];   // auditee uei => [component UEIs]
foreach ($pdo->query(
    "SELECT auditee_uei uei, additional_uei m FROM fac_additional_ueis WHERE additional_uei > ''
     UNION SELECT uei, related_uei m FROM entity_related_uei WHERE related_uei > ''") as $r) {
    $compOf[$r['uei']][] = $r['m'];
}

if ($retired) {
    $st = $pdo->prepare("DELETE FROM entity_map_point WHERE uei IN (" . implode(',', array_fill(0, count($retired), '?')) . ")");
    $st->execute($retired);
    printf("  UEI successions: dropped %d retired member dot(s); %d government(s) collapsed to their canonical UEI\n",
        $st->rowCount(), count($groupOf));
}

// --- 2) L1 (delinquent audits): the shared missing-year walk (lib/Rules.php) ---
// L1 is the most severe level, so it overrides the provisional level above.
//
// Candidates are EVERY mapped entity, not just federal >= $2M. The old >= $2M SQL prefilter
// hard-coded the expenditure proxy as the only way to confirm a missing year, so this build
// silently disagreed with the Evaluation dashboard and the profile, which also confirm via
// award activity: a sub-$2M entity with a missing year covered by a live award or a
// pass-through was Level 1 on those surfaces and unflagged on the map. aero_filing_status()
// applies the same two-signal rule (activity, else proxy) to all of them.
$cand = $pdo->query("SELECT e.uei FROM entity e JOIN entity_map_point m ON m.uei = e.uei")->fetchAll(PDO::FETCH_COLUMN);
$l1 = [];
foreach (array_chunk($cand, 5000) as $chunk) {
    // Look data up for the chunk PLUS any retired siblings (dropped above, so absent from $cand):
    // a government's L1 must be judged on its WHOLE history, not just its current UEI's slice.
    $lookup = [];
    foreach ($chunk as $u) {
        foreach ($groupOf[$u] ?? [$u] as $m) {
            $lookup[$m] = true;
            foreach ($compOf[$m] ?? [] as $c) $lookup[$c] = true;   // component UEIs, for the money queries
        }
    }
    $lk = array_keys($lookup);
    $in = implode(',', array_fill(0, count($lk), '?'));
    // federal_latest is the latest ACTIVE report's total_amount_expended (build_entity_directory.php)
    // — the same FAC figure the routes use for the proxy, never aero_score. Read off the canonical
    // member, which by definition holds the group's most recent audit (as recipients.php does).
    $st = $pdo->prepare("SELECT uei, COALESCE(federal_latest, 0) fed FROM entity WHERE uei IN ($in)");
    $st->execute($lk);
    $fed = [];
    foreach ($st as $r) $fed[$r['uei']] = (float) $r['fed'];

    $st = $pdo->prepare("SELECT auditee_uei uei, audit_year yr, MAX(fy_end_date) fy,
                                MAX(audit_period_covered='biennial') bi
                         FROM fac_general WHERE auditee_uei IN ($in) AND fy_end_date IS NOT NULL
                         GROUP BY auditee_uei, audit_year");
    $st->execute($lk);
    $byUei = [];
    // 'orig' => null: this build has no submitted_date, so filed years report 'filed' and
    // timeliness is simply not judged here (the map shows levels, not lateness).
    foreach ($st as $r) $byUei[$r['uei']][(int) $r['yr']] = ['fy' => $r['fy'], 'orig' => null, 'bi' => (int) $r['bi'] === 1];

    // Award-activity signals — the confirmation the routes have always applied.
    $iv = [];
    $st = $pdo->prepare("SELECT recipient_uei uei, period_start_date s, period_end_date e FROM usa_award
                         WHERE recipient_uei IN ($in) AND category IN ('grant','direct_payment')
                           AND period_start_date IS NOT NULL AND period_end_date IS NOT NULL");
    $st->execute($lk);
    foreach ($st as $r) $iv[$r['uei']][] = [$r['s'], $r['e']];
    $sy = [];
    try {
        $st = $pdo->prepare("SELECT sub_vendor_uei uei, year FROM subaward_edge WHERE sub_vendor_uei IN ($in)
                             GROUP BY sub_vendor_uei, year");
        $st->execute($lk);
        foreach ($st as $r) $sy[$r['uei']][(int) $r['year']] = true;
    } catch (\Throwable $e) { /* no edge table -> proxy + direct awards still apply (routes degrade the same way) */ }

    foreach ($chunk as $uei) {                       // mapped entities only — never a retired sibling
        $members = $groupOf[$uei] ?? [$uei];         // single-UEI entities: the group IS the entity
        $f = []; $ivM = []; $syM = [];
        foreach ($members as $m) {
            foreach ($byUei[$m] ?? [] as $yy => $x) { if (!isset($f[$yy])) $f[$yy] = $x; }   // FILINGS: succession only
            foreach ([$m, ...($compOf[$m] ?? [])] as $mm) {                                   // MONEY: + components
                foreach ($iv[$mm] ?? [] as $p) $ivM[] = $p;
                foreach ($sy[$mm] ?? [] as $yy => $_u) $syM[$yy] = true;
            }
        }
        if (!$f) continue;
        $status = aero_filing_status($f, aero_activity_confirmer($ivM, $syM), $fed[$uei] ?? 0.0);
        foreach ($status as $s) {
            if ($s['st'] === 'missing') { $l1[] = $uei; break; }   // >= 1 confirmed missing year = L1
        }
    }
}
foreach (array_chunk($l1, 5000) as $chunk) {
    $st = $pdo->prepare("UPDATE entity_map_point SET lvl=1 WHERE uei IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")");
    $st->execute($chunk);
}

// --- 3) L5/L6 split: promote repeat-topped entities (lvl=6) to L5 when chain depth >= 3 ---
$rep6 = $pdo->query("SELECT uei FROM entity_map_point WHERE lvl = 6")->fetchAll(PDO::FETCH_COLUMN);
$l5 = [];
foreach (array_chunk($rep6, 3000) as $chunk) {
    $in = implode(',', array_fill(0, count($chunk), '?'));
    $st = $pdo->prepare("SELECT f.auditee_uei uei, f.report_id, f.reference_number ref, f.audit_year yr,
                                f.is_repeat_finding rep, f.prior_finding_ref_numbers pr
                         FROM fac_findings f JOIN fac_general g ON g.report_id = f.report_id AND g.is_active = 1
                         WHERE f.auditee_uei IN ($in)");
    $st->execute($chunk);
    $fnd = [];
    foreach ($st as $r) $fnd[$r['uei']][] = $r;
    foreach ($fnd as $uei => $rows2) {
        $refYear = []; $prior = []; $rep = []; $latestYr = -1; $latestRid = null;
        foreach ($rows2 as $r) {
            $refYear[$r['ref']] = (int) $r['yr'];
            $isr = ((int) $r['rep']) === 1;
            $rep[$r['ref']] = $isr ? 1 : 0;
            if ($isr) $prior[$r['ref']] = aero_first_prior($r['pr']);
            if ((int) $r['yr'] > $latestYr) { $latestYr = (int) $r['yr']; $latestRid = $r['report_id']; }
        }
        $maps = ['refYear' => $refYear, 'prior' => $prior, 'rep' => $rep];
        $maxDepth = 0;
        foreach ($rows2 as $r) {
            if ($r['report_id'] !== $latestRid || ((int) $r['rep']) !== 1) continue;
            $maxDepth = max($maxDepth, Lineage::walk($r['ref'], $maps)['traced_depth']);
        }
        if ($maxDepth >= 3) $l5[] = $uei;
    }
}
foreach (array_chunk($l5, 5000) as $chunk) {
    $st = $pdo->prepare("UPDATE entity_map_point SET lvl=5 WHERE uei IN (" . implode(',', array_fill(0, count($chunk), '?')) . ")");
    $st->execute($chunk);
}

$n = (int) $pdo->query("SELECT COUNT(*) FROM entity_map_point")->fetchColumn();
$dist = [];
foreach ($pdo->query("SELECT lvl, COUNT(*) c FROM entity_map_point GROUP BY lvl ORDER BY lvl") as $r) $dist[] = "L{$r['lvl']}={$r['c']}";
printf("built entity_map_point: %d rows in %.1fs  [%s]\n", $n, microtime(true) - $a, implode(' ', $dist));
