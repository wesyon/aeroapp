<?php
declare(strict_types=1);

/**
 * Shared 2 CFR part 200 rule helpers used by the routes (grantee, evaluation,
 * recipients), the score pipeline (compute_scores) and the map precompute
 * (build_entity_map_point). These were previously transcribed independently at each
 * call site, which is how recipients.php ended up with a drifted (unclamped) copy of
 * the deadline math, and the map precompute with a proxy-only Level 1.
 *
 * Level-1 delinquency is the whole walk, not just its pieces: callers hand
 * aero_filing_status() a filing history (+ an aero_activity_confirmer()) and read back a
 * per-year status, rather than re-deriving the loop. Nothing below reads aero_score —
 * a paused or stale score must never move a compliance determination.
 *
 * Plain guarded functions (like Normalize.php) so the file is safe to include
 * more than once, and unit-testable in isolation (api/tests/rules_test.php).
 */
if (!function_exists('aero_add_months_clamped')) {
    /**
     * $date + $months (positive) with MONTH-END SEMANTICS: fiscal-year-ends are
     * month-ends and the single-audit deadline is the month-end $months out
     * (2 CFR 200.512), so a Jun-30 FYE is due Mar 31 — NOT Mar 30, which naive
     * day-of-month arithmetic gives (Jun has 30 days, Mar has 31). When the source
     * date is its month's last day, the result snaps to the TARGET month's last
     * day (Dec-31 -> Sep-30, Jun-30 -> Mar-31, Feb-29 -> Nov-30). Otherwise the
     * day-of-month is preserved, clamped to the target month's length — a rare
     * mid-month / 52-53-week FYE. Returns a midnight timestamp.
     */
    function aero_add_months_clamped(string $date, int $months): int
    {
        $e = strtotime($date);
        $y = (int) date('Y', $e);
        $m = (int) date('n', $e) + $months;
        $y += intdiv($m - 1, 12);
        $m = ($m - 1) % 12 + 1;
        $targetLen = (int) date('t', mktime(0, 0, 0, $m, 1, $y));
        $srcDay = (int) date('j', $e);
        $d = $srcDay === (int) date('t', $e) ? $targetLen : min($srcDay, $targetLen);
        return mktime(0, 0, 0, $m, $d, $y);
    }

    /** 2 CFR 200.512 submission deadline: fiscal-year end + 9 months (clamped). */
    function aero_deadline9(string $fyEnd): int
    {
        return aero_add_months_clamped($fyEnd, 9);
    }

    /**
     * Biennial audit cycles (2 CFR 200.504 lets a recipient audit biennially when
     * its statute requires it — e.g. the State of Montana). A biennial report's
     * audit_year is the SECOND year of its two-year period, so the prior year is
     * covered by it and the next filing is only expected two years on. True when
     * year $y needs no separate filing: either the year after it was filed as
     * biennial (its period includes $y), or the newest filing is biennial and $y
     * is off-cycle from it (an adjacent expected biennial period includes $y).
     *
     * @param array $biennialYears audit_year => true, for filed biennial reports
     * @param ?int  $lastFiledYear newest filed audit_year (null = no filings)
     */
    function aero_biennial_covered(int $y, array $biennialYears, ?int $lastFiledYear): bool
    {
        if (isset($biennialYears[$y + 1])) {
            return true;
        }
        return $lastFiledYear !== null && isset($biennialYears[$lastFiledYear])
            && ($lastFiledYear - $y) % 2 !== 0;
    }

    /**
     * Level-1 (missing-audit delinquency) decision for ONE missing-and-overdue fiscal year,
     * shared by /api/grantee (profile) and /api/evaluation (dashboard) so the two reconcile.
     * A missing year counts — returning its reason label — when federal award ACTIVITY confirms
     * the recipient was still active that FY (a direct-award period overlap or an FSRS pass-through
     * subaward, passed in as $activitySrc = 'award'|'subaward'), OR, failing that, when the
     * expenditure PROXY fires (latest filed federal expenditures >= ~2x the $1M single-audit
     * threshold). Returns null when neither holds — the year is overdue but unconfirmed (a caveated
     * "verify", not counted). $federalLatest MUST come from the authoritative FAC filing
     * (fac_general.total_amount_expended), not aero_score, so a paused/stale score can't shift it.
     */
    function aero_l1_confirmed_by(?string $activitySrc, float $federalLatest, int $proxyThreshold = 2000000): ?string
    {
        if ($activitySrc !== null && $activitySrc !== '') {
            return $activitySrc;
        }
        return $federalLatest >= $proxyThreshold ? 'proxy' : null;
    }

    /**
     * Build the award-ACTIVITY confirmer aero_filing_status() consults for an overdue year:
     * 'award' when a direct award's period of performance overlaps the fiscal year, else
     * 'subaward' when an FSRS pass-through landed in (or within $subLookback years before) it,
     * else null. Direct overlap is precise, so it wins the label over a year-granular subaward.
     *
     * Absence of activity is NOT evidence a year wasn't required (consolidated audit UEIs don't
     * align to award UEIs, the $30k FSRS reporting floor, prime underreporting, pass-through
     * invisibility), so a null here only ever downgrades a year to a caveated "verify" — it never
     * removes a year the expenditure proxy would count.
     *
     * Both feeds carry dirty dates (observed years 0022..2209), so periods are clamped here rather
     * than at each call site: an implausible start drops the row, a future end clamps to now. The
     * signal is positive-only, so dropping a row is always safe.
     *
     * @param array $rawIntervals list of [start, end] direct-award periods as 'Y-m-d' strings
     * @param array $subYears     year => true — FSRS pass-through years received as sub_vendor
     */
    function aero_activity_confirmer(array $rawIntervals, array $subYears, int $subLookback = 2): callable
    {
        $now = time();
        $minPlausible = strtotime('1980-01-01');
        $iv = [];
        foreach ($rawIntervals as [$s, $e]) {
            $s = is_int($s) ? $s : strtotime((string) $s);
            $e = is_int($e) ? $e : strtotime((string) $e);
            if ($s === false || $e === false || $s < $minPlausible) continue;   // drop implausible start
            if ($e > $now) $e = $now;                                           // clamp dirty future end
            if ($e >= $s) $iv[] = [$s, $e];
        }
        return static function (int $fyStartTs, int $fyEndTs) use ($iv, $subYears, $subLookback): ?string {
            foreach ($iv as [$s, $e]) {
                if ($s <= $fyEndTs && $e >= $fyStartTs) return 'award';          // period overlaps the FY
            }
            $lo = (int) date('Y', $fyStartTs) - $subLookback;
            $hi = (int) date('Y', $fyEndTs);
            foreach ($subYears as $yy => $_unused) {
                if ($yy >= $lo && $yy <= $hi) return 'subaward';                 // pass-through in/just before FY
            }
            return null;
        };
    }

    /**
     * Per-year filing status across an entity's audit history — the ONE missing-year walk behind
     * Level-1 delinquency on every surface (Evaluation dashboard, entity profile, map precompute).
     * aero_l1_confirmed_by() is the per-year decision; this is the loop around it, which used to be
     * transcribed at four call sites — which is how the map precompute ended up proxy-only, silently
     * dropping the activity-confirmed sub-$2M entities the dashboard counts as Level 1.
     *
     * A year is 'missing' only when it is unfiled, past its 2 CFR 200.512 deadline, not inside a
     * biennial period, AND confirmed by aero_l1_confirmed_by(); an unconfirmed overdue year is
     * 'unverified' (surfaced as a caveat, never counted). Late-filed years are reported as 'late'
     * but are NOT Level 1 — the level counts not-filed years only.
     *
     * Unfiled years get a synthetic fiscal-year end from the NEWEST filing's month/day, the only
     * cadence evidence available (a mid-month/52-53-week FYE can drift a day; the deadline's
     * month-end clamping absorbs it).
     *
     * @param array     $filings audit_year => ['fy' => 'Y-m-d', 'orig' => 'Y-m-d'|null, 'bi' => bool].
     *                           'orig' = ORIGINAL submission date; null when the caller has no
     *                           timeliness data (the map precompute) -> that year reports 'filed'.
     * @param ?callable $confirm from aero_activity_confirmer(); null = no activity feed wired,
     *                           so the expenditure proxy decides alone.
     * @param float     $federalLatest latest FILED federal expenditures — fac_general.total_amount_expended
     *                           or its entity.federal_latest denormalization, NEVER aero_score.
     * @param ?int      $from    first year reported (default: earliest filed year)
     * @param ?int      $to      last year reported (default: current calendar year)
     * @return array year => ['st'           => 'ontime'|'late'|'filed'|'covered'|'na'|'pending'|'missing'|'unverified',
     *                        'fy_end'       => 'Y-m-d'|null  (actual when filed, synthetic when projected),
     *                        'days'         => ?int  (filed: days past deadline, negative = early;
     *                                                 missing/unverified: days overdue),
     *                        'confirmed_by' => ?string  ('award'|'subaward'|'proxy' on 'missing')]
     */
    function aero_filing_status(array $filings, ?callable $confirm, float $federalLatest, ?int $from = null, ?int $to = null): array
    {
        if (!$filings) return [];
        $minY = min(array_keys($filings));
        $lastY = max(array_keys($filings));
        $bi = [];
        foreach ($filings as $y => $x) {
            if (!empty($x['bi'])) $bi[(int) $y] = true;
        }
        $lastFyTs = strtotime((string) $filings[$lastY]['fy']);
        $fm = (int) date('n', $lastFyTs);
        $fd = (int) date('j', $lastFyTs);
        $from ??= $minY;
        $to ??= (int) date('Y');
        $now = time();
        $out = [];
        for ($y = $from; $y <= $to; $y++) {
            if (isset($filings[$y])) {
                $fy = (string) $filings[$y]['fy'];
                $orig = $filings[$y]['orig'] ?? null;
                if ($orig === null) {                       // no submission date -> can't judge timeliness
                    $out[$y] = ['st' => 'filed', 'fy_end' => $fy, 'days' => null, 'confirmed_by' => null];
                    continue;
                }
                // rounded to whole days: both sides are dated (midnight), so a fraction is only ever
                // a DST artifact — rounding keeps 'late' from wobbling by an hour across the shift.
                $days = (int) round((strtotime((string) $orig) - aero_deadline9($fy)) / 86400);
                $out[$y] = ['st' => $days > 0 ? 'late' : 'ontime', 'fy_end' => $fy, 'days' => $days, 'confirmed_by' => null];
                continue;
            }
            // biennial BEFORE the pre-history check: a biennial period's prior FY can precede $minY
            if (aero_biennial_covered($y, $bi, $lastY)) {
                $out[$y] = ['st' => 'covered', 'fy_end' => null, 'days' => null, 'confirmed_by' => null];
                continue;
            }
            if ($y < $minY) {                               // before the filing history began
                $out[$y] = ['st' => 'na', 'fy_end' => null, 'days' => null, 'confirmed_by' => null];
                continue;
            }
            $fyEndTs = mktime(0, 0, 0, $fm, $fd, $y);
            $fyEnd = date('Y-m-d', $fyEndTs);
            $dl = aero_deadline9($fyEnd);
            if ($dl >= $now) {                              // trailing edge: not yet due
                $out[$y] = ['st' => 'pending', 'fy_end' => $fyEnd, 'days' => null, 'confirmed_by' => null];
                continue;
            }
            $src = $confirm ? $confirm(mktime(0, 0, 0, $fm, $fd, $y - 1), $fyEndTs) : null;   // ~1yr FY window
            $cb = aero_l1_confirmed_by($src, $federalLatest);
            $out[$y] = [
                'st'           => $cb !== null ? 'missing' : 'unverified',
                'fy_end'       => $fyEnd,
                'days'         => (int) floor(($now - $dl) / 86400),
                'confirmed_by' => $cb,
            ];
        }
        return $out;
    }

    /**
     * First prior-finding reference from FAC's free-text prior_finding_ref_numbers
     * (comma- or semicolon-separated list; blanks and 'N/A' -> ''). The repeat-
     * lineage walks hop through this single first reference per finding.
     */
    function aero_first_prior(?string $pr): string
    {
        $p = trim((string) $pr);
        if ($p === '' || strcasecmp($p, 'N/A') === 0) {
            return '';
        }
        return trim(explode(',', str_replace(';', ',', $p))[0]);
    }

    /**
     * Distinct audit YEARS named in FAC's prior_finding_ref_numbers, parsed from the
     * leading YYYY of every reference in the list (e.g. "2024-016, 2023-020, …, 2011-056"
     * -> [2024, 2023, …, 2011]). Thorough auditors enumerate a finding's entire multi-year
     * lineage here, so this recovers the recurrence span the auditor DOCUMENTED — including
     * years that predate our data window and so can't be hop-resolved against loaded
     * findings. Returns [] for blanks / 'N/A'. Years are bounded to 1990..current+1 so a
     * stray number in a reference can't fabricate a year.
     */
    function aero_prior_years(?string $pr): array
    {
        $p = trim((string) $pr);
        if ($p === '' || strcasecmp($p, 'N/A') === 0) {
            return [];
        }
        $max = (int) date('Y') + 1;
        $years = [];
        foreach (preg_split('/[,;]+/', $p) as $tok) {
            if (preg_match('/(19|20)\d{2}/', trim($tok), $m)) {
                $y = (int) $m[0];
                if ($y >= 1990 && $y <= $max) $years[$y] = true;
            }
        }
        return array_keys($years);
    }
}
