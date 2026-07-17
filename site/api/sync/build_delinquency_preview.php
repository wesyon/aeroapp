<?php
declare(strict_types=1);

/**
 * Build delinquency_preview — Layers 2-5 of docs/DELINQUENCY_METHODOLOGY.md, computed ALONGSIDE
 * the live rule so the two can be compared. Nothing here drives a live surface.
 *
 * Per entity-year that Layer 1 says is expected-and-missing, this estimates the expenditure from
 * money, compares it to the trigger IN FORCE for that fiscal year, and classifies by what the
 * evidence supports. It also records what TODAY's rule concluded for the same year, so the
 * dashboard can show the delta rather than assert a new number.
 *
 *   php api/sync/build_delinquency_preview.php
 *
 * PREVIEW CAVEATS (see §5 of the doc) — the inputs are known-imperfect and the figures WILL move:
 *   - outlays: ~800k awards still hold pre-fix values (per-FY error reached 59%)
 *   - subawards: subaward_edge is YEAR-grain; 73.7% of entities have an FY spanning two calendar
 *     years, so the pass-through leg cannot be windowed precisely
 *   - coverage: the USAspending crawl is FAC-seeded, so never-filers cannot appear at all
 *   - loans: only 704 of 12,759 loan awards carry a value, and "continuing compliance" is not exposed
 */

$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
require $root . '/lib/Rules.php';
require $root . '/lib/UeiGroups.php';
require $root . '/lib/MoneyTrust.php';
require $root . '/lib/Delinquency.php';
Env::load(dirname($root, 2) . '/.env');
Env::load(dirname($root) . '/.env');
$pdo = Db::connect();
$t0 = microtime(true);

$pdo->exec("DROP TABLE IF EXISTS delinquency_preview");
$pdo->exec("CREATE TABLE delinquency_preview (
  uei CHAR(12) NOT NULL, fy SMALLINT NOT NULL,
  class VARCHAR(16) NOT NULL,            -- covered|observed|bracketed|committed|persistent|exempt|indeterminate
  fy_end DATE NOT NULL,
  trigger_amt DECIMAL(18,2) NOT NULL,    -- the trigger IN FORCE for this FY (keyed on FY start)
  estimate DECIMAL(18,2) NOT NULL,       -- max(outlay floor, obligation floor); for exposure ranking
  outlays DECIMAL(18,2) NOT NULL DEFAULT 0,
  subawards DECIMAL(18,2) NOT NULL DEFAULT 0,
  loans DECIMAL(18,2) NOT NULL DEFAULT 0,
  pop_oblig DECIMAL(18,2) NOT NULL DEFAULT 0,
  prior_sefa DECIMAL(18,2) NOT NULL DEFAULT 0,
  exposure DECIMAL(18,2) NOT NULL DEFAULT 0,
  money_trust VARCHAR(16) NOT NULL DEFAULT 'ok',
  covered TINYINT NOT NULL DEFAULT 0,        -- did we actually crawl this recipient's awards?
  legacy_missing TINYINT NOT NULL DEFAULT 0,   -- what TODAY's rule says for this same year
  basis VARCHAR(255) NULL,
  PRIMARY KEY (uei, fy), KEY idx_dp_class (class), KEY idx_dp_exp (exposure)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ---- shared inputs -----------------------------------------------------------------------
$grp = aero_uei_groups($pdo);
$members = $grp['canon'];        // succession group (state_uei) — drives the FILING history
$retired = $grp['retired'];
$trust = aero_money_trust($pdo);

// MONEY family: a component files its own awards under its OWN UEI but is covered by the parent's
// CONSOLIDATED audit, so the money must roll up to the auditee even though the filing does not.
// Same rollup the USAspending tab / profile use: fac_additional_ueis (declared on the SF-SAC) UNION
// entity_related_uei (curated component links, money-only by design — see the OK/NY crosswalk work).
//
// This is DELIBERATELY separate from the succession group: successions merge FILINGS (one
// government, one audit history); component links merge MONEY only, leaving the eval untouched.
// Without it, the State of Nevada showed $0 of federal money and fell to Unknown while its
// component agencies (Dept of HHS $30B, DOT $3.7B, ...) held $49.5B and $6.9B of FY2024 outlays.
$moneyFam = [];   // auditee uei => [own uei + succession members + component UEIs]
$parentOf = [];   // component uei => [parent auditee UEIs]  (the reverse — for the COVERED grade)
foreach ($pdo->query(
    "SELECT auditee_uei uei, additional_uei m FROM fac_additional_ueis WHERE additional_uei IS NOT NULL AND additional_uei <> ''
     UNION SELECT uei, related_uei m FROM entity_related_uei WHERE related_uei IS NOT NULL AND related_uei <> ''") as $r) {
    $moneyFam[$r['uei']][$r['m']] = true;
    $parentOf[$r['m']][] = $r['uei'];
}

// COVERED grade: a component is not separately delinquent for a year its PARENT audited — its
// spending is inside the parent's consolidated Single Audit. Preload the years each parent filed
// (active reports only), so the per-year check is a hash lookup. Parents only (~1,900 UEIs).
$parentFiled = [];   // parent uei => [audit_year => true]
if ($parentOf) {
    $parents = array_keys(array_flip(array_merge(...array_values($parentOf))));
    foreach (array_chunk($parents, 5000) as $pchunk) {
        $pin = implode(',', array_fill(0, count($pchunk), '?'));
        $st = $pdo->prepare("SELECT auditee_uei uei, audit_year yr FROM fac_general
                             WHERE auditee_uei IN ($pin) AND is_active = 1 GROUP BY auditee_uei, audit_year");
        $st->execute($pchunk);
        foreach ($st as $r) $parentFiled[$r['uei']][(int) $r['yr']] = true;
    }
}

$fed = [];
foreach ($pdo->query("SELECT uei, COALESCE(federal_latest,0) f FROM entity WHERE latest_audit_year IS NOT NULL") as $r) {
    $fed[$r['uei']] = (float) $r['f'];
}
$subjects = array_values(array_diff(array_keys($fed), array_keys($retired)));
printf("entities to assess (successions collapsed): %s\n", number_format(count($subjects)));

$ins = $pdo->prepare("INSERT INTO delinquency_preview
  (uei, fy, class, fy_end, trigger_amt, estimate, outlays, subawards, loans, pop_oblig,
   prior_sefa, exposure, money_trust, covered, legacy_missing, basis)
  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

// filing family (succession) vs money family (succession + components). Filings use the first,
// dollars use the second — see $moneyFam above.
$filFamOf   = static fn (string $u): array => $members[$u] ?? [$u];
$moneyFamOf = static function (string $u) use ($members, $moneyFam): array {
    $fam = [];
    foreach ($members[$u] ?? [$u] as $m) {
        $fam[$m] = true;
        foreach ($moneyFam[$m] ?? [] as $c => $_x) $fam[$c] = true;
    }
    return array_keys($fam);
};

$rows = 0;
$dist = [];
foreach (array_chunk($subjects, 4000) as $ci => $chunk) {
    $lookup = [];
    foreach ($chunk as $u) foreach ($moneyFamOf($u) as $m) $lookup[$m] = true;   // pull component rows too
    $lk = array_keys($lookup);
    $in = implode(',', array_fill(0, count($lk), '?'));

    // filings (+ per-year SEFA, for the bracket test)
    $st = $pdo->prepare("SELECT auditee_uei uei, audit_year yr, MAX(fy_end_date) fy, MIN(submitted_date) orig,
                                MAX(audit_period_covered='biennial') bi, MAX(total_amount_expended) sefa
                         FROM fac_general WHERE auditee_uei IN ($in) AND fy_end_date IS NOT NULL
                         GROUP BY auditee_uei, audit_year");
    $st->execute($lk);
    $fil = []; $sefa = [];
    foreach ($st as $r) {
        $fil[$r['uei']][(int) $r['yr']] = ['fy' => $r['fy'], 'orig' => $r['orig'], 'bi' => (int) $r['bi'] === 1];
        $sefa[$r['uei']][(int) $r['yr']] = (float) $r['sefa'];
    }
    // awards: periods (PoP), obligations, loan values
    $st = $pdo->prepare("SELECT recipient_uei uei, category, period_start_date s, period_end_date e,
                                COALESCE(total_obligation,0) obl, COALESCE(total_loan_value,0) loan
                         FROM usa_award WHERE recipient_uei IN ($in)");
    $st->execute($lk);
    $aw = [];
    foreach ($st as $r) $aw[$r['uei']][] = $r;
    // outlays by month
    $st = $pdo->prepare("SELECT a.recipient_uei uei, o.ym, SUM(o.outlay) v
                         FROM usa_award_outlay_month o JOIN usa_award a ON a.award_id = o.award_id
                         WHERE a.recipient_uei IN ($in) GROUP BY a.recipient_uei, o.ym");
    $st->execute($lk);
    $out = [];
    foreach ($st as $r) $out[$r['uei']][(string) $r['ym']] = (float) $r['v'];
    // pass-through by year (YEAR-grain — see the caveat above)
    $st = $pdo->prepare("SELECT sub_vendor_uei uei, year y, SUM(total_amount) v FROM subaward_edge
                         WHERE sub_vendor_uei IN ($in) GROUP BY sub_vendor_uei, year");
    $st->execute($lk);
    $sub = [];
    foreach ($st as $r) $sub[$r['uei']][(int) $r['y']] = (float) $r['v'];

    // COVERAGE is computed per entity below as "we hold award records for them" — see the note at
    // the $covered assignment. "The crawler ran" is NOT sufficient: the State of Nevada had been
    // crawled and still has zero award rows, which made R5 declare a $9.7B recipient exempt.

    foreach ($chunk as $uei) {
        // FILINGS from the succession family only (components are covered by the parent's audit,
        // they do not file their own — entity_related_uei is money-only by design).
        $f = []; $mySefa = [];
        foreach ($filFamOf($uei) as $m) {
            foreach ($fil[$m] ?? [] as $y => $x) if (!isset($f[$y])) { $f[$y] = $x; $mySefa[$y] = $sefa[$m][$y] ?? 0.0; }
        }
        if (!$f) continue;

        // MONEY from the whole component family: the money lands under component UEIs even though
        // the audit is filed by the parent (the State of Nevada = $0 solo, $6.9B FY2024 as a family).
        $myAw = []; $myOut = []; $mySub = []; $covered = false;
        foreach ($moneyFamOf($uei) as $m) {
            foreach ($aw[$m] ?? [] as $a) $myAw[] = $a;
            foreach ($out[$m] ?? [] as $ym => $v) $myOut[$ym] = ($myOut[$ym] ?? 0) + $v;
            foreach ($sub[$m] ?? [] as $y => $v) $mySub[$y] = ($mySub[$y] ?? 0) + $v;
            // COVERED = we hold award records for the family. Not "the crawler ran": Nevada's own
            // UEI has zero awards, but its components hold $49.5B — so the family IS covered.
            if (!empty($aw[$m])) $covered = true;
        }
        $priorSefa = $fed[$uei] ?? 0.0;
        $moneyOk = ($trust[$uei]['verdict'] ?? 'ok') === 'ok';
        $filedYears = array_keys($f);

        // Layer 1 — which years are expected-and-missing (and what TODAY's rule says about them)
        $ivRaw = []; $syYears = [];
        foreach ($myAw as $a) {
            if (in_array($a['category'], ['grant', 'direct_payment'], true) && $a['s'] && $a['e']) $ivRaw[] = [$a['s'], $a['e']];
        }
        foreach ($mySub as $y => $_v) $syYears[$y] = true;
        $status = aero_filing_status($f, aero_activity_confirmer($ivRaw, $syYears), $priorSefa);

        foreach ($status as $y => $s) {
            if ($s['st'] !== 'missing' && $s['st'] !== 'unverified') continue;   // only the overdue-unfiled years
            $fyEnd = (string) $s['fy_end'];
            $eTs = strtotime($fyEnd);
            $sTs = strtotime('+1 day', aero_add_months_clamped($fyEnd, -12));    // FY start

            // Layer 2 — estimate the floor
            $oOut = 0.0;
            foreach ($myOut as $ym => $v) { $m = strtotime($ym); if ($m >= $sTs && $m <= $eTs) $oOut += $v; }
            $oSub = $mySub[$y] ?? 0.0;                                           // YEAR-grain approximation
            $oLoan = 0.0; $oPop = 0.0; $oPopLocal = 0.0; $popReaches = false;
            foreach ($myAw as $a) {
                if ((float) $a['loan'] > 0) $oLoan += (float) $a['loan'];        // 200.502(b) stock term
                if (!$a['s'] || !$a['e']) continue;
                $as = strtotime((string) $a['s']); $ae = strtotime((string) $a['e']);
                if ($as === false || $ae === false || $ae < $as) continue;
                if ($as <= $eTs && $ae >= $sTs) {
                    $popReaches = true;
                    // straight-line: obligation across the PoP, take the months inside this FY
                    $totM = max(1, (int) round(($ae - $as) / 2629746));
                    $ovM = max(0, (int) round((min($ae, $eTs) - max($as, $sTs)) / 2629746));
                    if ((float) $a['obl'] > 0) {
                        $frac = $ovM / $totM;
                        $contrib = ((float) $a['obl']) * $frac;
                        $oPop += $contrib;
                        // PoP >= 75% inside this FY -> the obligation genuinely belongs to this year
                        // (not a thin slice of a multi-year award). Drives the strong "committed" grade.
                        if ($frac >= 0.75) $oPopLocal += $contrib;
                    }
                }
            }
            // Two money floors — SPENT vs COMMITTED — kept separate so classify() can grade them
            // distinctly (Observed on the outlay floor, Committed on the obligation floor):
            //   OUTLAY floor    = cash actually disbursed + pass-through + loans. The truest signal,
            //                     but File C outlays are cumulative, reverse-engineered to the FY, and
            //                     ~800k awards hold pre-fix values (per-FY error to 59%).
            //   OBLIGATION floor= PoP-ALLOCATED obligations (a multi-year award's commitment spread
            //                     straight-line across its period, NOT dumped in the obligation year)
            //                     + pass-through + loans. More completely populated and immune to the
            //                     outlay reconstruction issues — but COMMITTED money, not confirmed SPEND.
            $estOutlay     = $oOut + $oSub + $oLoan;
            $estOblig      = $oPop + $oSub + $oLoan;         // all obligations (incl. multi-year slices)
            $estObligLocal = $oPopLocal + $oSub + $oLoan;    // only PoP-within-FY obligations
            $estimate      = max($estOutlay, $estOblig);     // for exposure ranking only; the GRADE uses each floor

            // nearest filed years either side, for the bracket test
            $before = null; $after = null;
            foreach ($filedYears as $fy2) {
                if ($fy2 < $y && ($before === null || $fy2 > $before)) $before = $fy2;
                if ($fy2 > $y && ($after === null || $fy2 < $after)) $after = $fy2;
            }
            // COVERED: is this UEI a component whose parent filed an audit for THIS year?
            $parentAudited = false;
            foreach ($parentOf[$uei] ?? [] as $par) {
                if (!empty($parentFiled[$par][$y])) { $parentAudited = true; break; }
            }
            $ctx = [
                'fy_end' => $fyEnd, 'estimate' => $estimate, 'money_ok' => $moneyOk,
                'filed_before' => $before !== null ? ($mySefa[$before] ?? 0.0) : null,
                'filed_after' => $after !== null ? ($mySefa[$after] ?? 0.0) : null,
                'prior_sefa' => $priorSefa, 'pop_reaches' => $popReaches,
                'has_subaward' => $oSub > 0, 'has_loan' => $oLoan > 0,
                'covered' => $covered, 'parent_audited' => $parentAudited,
                'est_outlay' => $estOutlay, 'est_oblig' => $estOblig, 'est_oblig_local' => $estObligLocal,
                'outlays' => $oOut, 'subawards' => $oSub, 'loans' => $oLoan, 'pop_oblig' => $oPop,
            ];
            $class = aero_delinquency_classify($ctx);
            $dist[$class] = ($dist[$class] ?? 0) + 1;
            $ins->execute([
                $uei, $y, $class, $fyEnd, aero_trigger_for_fy($fyEnd), round($estimate, 2),
                round($oOut, 2), round($oSub, 2), round($oLoan, 2), round($oPop, 2),
                round($priorSefa, 2), round(aero_delinquency_exposure($ctx), 2),
                $trust[$uei]['verdict'] ?? 'ok', $covered ? 1 : 0, $s['st'] === 'missing' ? 1 : 0,
                substr(aero_delinquency_basis($ctx, $class), 0, 255),
            ]);
            $rows++;
        }
    }
    fprintf(STDERR, "  chunk %d done (%s rows)\n", $ci, number_format($rows));
}
ksort($dist);
$parts = [];
foreach ($dist as $k => $v) $parts[] = "$k=" . number_format($v);
printf("built delinquency_preview: %s entity-years in %.1fs  [%s]\n", number_format($rows), microtime(true) - $t0, implode(' ', $parts));
