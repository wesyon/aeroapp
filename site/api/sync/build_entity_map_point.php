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
 *   - L1 (delinquent audits): the exact missing-year delinquency loop (2 CFR 200.512 deadline +
 *     biennial coverage + the federal >= $2M "likely" gate), via lib/Rules.php.
 *   - L5/L6 (2+yr vs 1st-yr repeats): the lineage-depth walk (lib/Lineage.php) — depth >= 3 = L5.
 *
 * Re-runnable (full rebuild). Run after FAC sync + the zip_centroid seed.
 *   php api/sync/build_entity_map_point.php
 */

$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
require $root . '/lib/Rules.php';      // aero_deadline9 / aero_biennial_covered / aero_first_prior
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

// --- 2) L1 (delinquent audits): exact missing-year loop for federal >= $2M entities ---
// L1 is the most severe level, so it overrides the provisional level above.
$cand = $pdo->query("SELECT e.uei FROM entity e JOIN entity_map_point m ON m.uei = e.uei
                     WHERE e.federal_latest >= 2000000")->fetchAll(PDO::FETCH_COLUMN);
$l1 = [];
foreach (array_chunk($cand, 5000) as $chunk) {
    $in = implode(',', array_fill(0, count($chunk), '?'));
    $st = $pdo->prepare("SELECT auditee_uei uei, audit_year yr, MAX(fy_end_date) fy,
                                MAX(audit_period_covered='biennial') bi
                         FROM fac_general WHERE auditee_uei IN ($in) AND fy_end_date IS NOT NULL
                         GROUP BY auditee_uei, audit_year");
    $st->execute($chunk);
    $byUei = [];
    foreach ($st as $r) $byUei[$r['uei']][(int) $r['yr']] = ['fy' => $r['fy'], 'bi' => (int) $r['bi'] === 1];
    foreach ($byUei as $uei => $f) {
        if (!$f) continue;
        $fBi = [];
        foreach ($f as $y => $x) if ($x['bi']) $fBi[$y] = true;
        $lastYr = max(array_keys($f));
        $fm = (int) date('n', strtotime($f[$lastYr]['fy']));
        $fd = (int) date('j', strtotime($f[$lastYr]['fy']));
        $missing = 0;
        for ($y = min(array_keys($f)) + 1; $y <= (int) date('Y'); $y++) {
            if (isset($f[$y])) continue;
            if (aero_biennial_covered($y, $fBi, $lastYr)) continue;
            $fyEnd = date('Y-m-d', mktime(0, 0, 0, $fm, $fd, $y));
            if (aero_deadline9($fyEnd) >= time()) break;   // trailing edge: not yet due
            $missing++;
        }
        if ($missing > 0) $l1[] = $uei;
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
