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
     * $date + $months (positive) with END-OF-MONTH CLAMPING: the day of month is
     * preserved but clamped to the target month's length, so Dec-31 + 9 months
     * lands on Sep-30 — naive strtotime('+9 months') rolls the non-existent
     * Sep-31 forward to Oct-1. Returns a midnight timestamp.
     */
    function aero_add_months_clamped(string $date, int $months): int
    {
        $e = strtotime($date);
        $y = (int) date('Y', $e);
        $m = (int) date('n', $e) + $months;
        $y += intdiv($m - 1, 12);
        $m = ($m - 1) % 12 + 1;
        $d = min((int) date('j', $e), (int) date('t', mktime(0, 0, 0, $m, 1, $y)));
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
