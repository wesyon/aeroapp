<?php
declare(strict_types=1);

/**
 * Shared 2 CFR part 200 rule helpers used by the routes (grantee, evaluation,
 * recipients) and the score pipeline (compute_scores). These were previously
 * transcribed independently at each call site, which is how recipients.php
 * ended up with a drifted (unclamped) copy of the deadline math.
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
