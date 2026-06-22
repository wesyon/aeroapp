<?php
declare(strict_types=1);

/**
 * AERO Risk Score — pure scoring logic (no DB, no I/O), so it is unit-testable.
 *
 * Seven components, each scored 0-100 (higher = more risk), combined into a
 * weighted composite and a five-band tier. Rules transcribe the AERO Score
 * methodology (2 CFR part 200 authorities noted per component).
 *
 * The compute CLI (api/sync/compute_scores.php) assembles one "entity bundle"
 * per recipient UEI and calls Score::compute(). Bundle shape:
 *   [
 *     'latest_year'     => int,
 *     'years'           => [int,...],            // audit years present, asc
 *     'has_passthrough' => bool,                 // any pass-through (subaward) activity
 *     'by_year'         => [ year => [
 *          'mw','sd','qc','modified','repeat','fin_mw','fin_find','sub_find',
 *          'sub_mw','total_findings' => int,
 *          'going_concern','material_noncompliance' => bool,
 *          'days_late' => int|null,              // submission days past the 9-month deadline
 *       ], ... ],
 *     'cap'             => ['coverage'=>float,'quality'=>float,'available'=>bool],
 *   ]
 */
final class Score
{
    /** Component weights (percent); must sum to 100. */
    public const WEIGHTS = [
        'internal_control'     => 25,
        'repeat_findings'      => 20,
        'questioned_costs'     => 15,
        'reporting_timeliness' => 15,
        'cash_financial'       => 10,
        'subrecipient'         => 10,
        'cap_quality'          => 5,
    ];

    /** Tunables (documented judgment calls where the methodology is qualitative). */
    public const REPEAT_DAMP   = 0.5;  // historical 3-4yr chain interrupted by a clean latest year
    public const TIMELY_DAMP_2 = 40;   // damped score for >=2 prior delinquent yrs when latest on-time
    public const TIMELY_DAMP_1 = 20;   // damped score for 1 prior delinquent yr when latest on-time
    public const DELINQUENT_DAYS = 365; // a fiscal year is "delinquent" if filed >1 year (365d) late

    // Component 3 questioned-cost DOLLAR bumps, added to the count base. Amounts come
    // from finding-text extraction (fac_finding_extract.qc_amount, trusted bases),
    // which FAC's structured data does not expose. Breakpoints set on the observed
    // distribution: $1M ≈ top 6.5% of audits, 10%-of-expended ≈ top 10%. Severity is
    // max(absolute tier, ratio tier). [min threshold, bump], evaluated high→low.
    public const QC_DOLLAR_TIERS = [[1000000, 30], [250000, 20], [25000, 10]];
    public const QC_RATIO_TIERS  = [[0.10, 30], [0.03, 20], [0.01, 10]];

    private static function clamp(float $v): float
    {
        return max(0.0, min(100.0, $v));
    }

    /** Map a 0-100 composite to its tier band. Score 0 (no scored findings) is
     *  separated from Minimal so oversight can ignore the truly clean from the merely
     *  low-risk — the bottom band is otherwise ~90% of entities, two-thirds at exactly 0. */
    public static function tier(float $s): string
    {
        if ($s <= 0) return 'Clean';
        if ($s < 20) return 'Minimal';
        if ($s < 40) return 'Moderate';
        if ($s < 60) return 'Elevated';
        if ($s < 80) return 'Substantial';
        return 'Severe';
    }

    /** 1 — Internal Control (2 CFR 200.303): MWs/SDs in the latest audit. */
    public static function internalControl(array $latest): array
    {
        $mw = (int) $latest['mw'];
        $sd = (int) $latest['sd'];
        // Base scales with material-weakness count (severity of the control breakdown),
        // preserving the rubric anchors at 1 MW (55) and 2 MW (80).
        if ($mw >= 10)     $base = 95;
        elseif ($mw >= 5)  $base = 90;
        elseif ($mw >= 3)  $base = 85;
        elseif ($mw === 2) $base = 80;
        elseif ($mw === 1) $base = 55;
        elseif ($sd >= 2)  $base = 55;
        elseif ($sd >= 1)  $base = 25;
        else               $base = 0;
        // +5 per financial-system MW (cost principles/cash/program income), capped +15
        $bump = min(15, 5 * (int) $latest['fin_mw']);
        // +15 when a material weakness ITSELF recurs (an MW finding flagged as a repeat) —
        // the same control deficiency persisting, not merely "some MW in both years".
        $mwRepeat = (int) ($latest['mw_repeat'] ?? 0) > 0;
        if ($mwRepeat) $bump += 15;
        return ['score' => self::clamp($base + $bump),
                'mw' => $mw, 'sd' => $sd, 'fin_mw' => (int) $latest['fin_mw'], 'mw_repeat' => $mwRepeat];
    }

    /**
     * 2 — Repeat Findings (2 CFR 200.511): latest repeat count + how long the worst
     * single issue has actually persisted.
     *
     * `chain` is the LINEAGE DEPTH — the number of distinct audit years one finding
     * traces through via prior_finding_ref_numbers — computed upstream in
     * compute_scores.php (the year-level "any repeat this year" proxy is gone). A
     * finding flagged is_repeat_finding=1 whose prior predates our data window still
     * counts as depth >= 2, because the flag confirms a prior exists even when we
     * can't load it; so this never under-credits a genuine repeat.
     */
    public static function repeatFindings(array $e, array $latest): array
    {
        $n = (int) $latest['repeat'];
        $chain = (int) ($e['lineage_depth'] ?? 0);   // pointer-based single-issue lineage depth
        // Base — persistence (chain) is the dominant axis. A breadth-only repeat
        // problem (chain <= 2 with several findings recurring once) ranks BELOW
        // genuine multi-year persistence, rather than tying with it at 85.
        if ($chain >= 5)        $base = 100;
        elseif ($chain === 4)   $base = 90;
        elseif ($chain === 3)   $base = 85;
        elseif ($n >= 3)        $base = 75;   // breadth only (no 3+ year chain)
        elseif ($n === 2)       $base = 55;
        elseif ($n === 1)       $base = 30;
        else                    $base = 0;

        // Recovery damping: if the latest audit is clean (no repeats) but a single
        // issue had persisted 3-4 years, damp rather than fully erase the history.
        $damped = false;
        if ($n === 0 && $chain >= 3 && $chain <= 4) {
            $base = (int) round($base * self::REPEAT_DAMP);
            $damped = true;
        }

        // Breadth bonus — the base credits recurrence breadth only up to n=3, so
        // add diminishing, capped resolution for entities with many findings
        // recurring at once. Separates a 30-repeat offender from a 3-repeat one,
        // and lets deep+broad worst cases reach 100 within the current window.
        $bonus = self::breadthBonus($n);

        return ['score' => self::clamp($base + $bonus), 'n_latest' => $n,
                'chain' => $chain, 'breadth_bonus' => $bonus, 'damped' => $damped];
    }

    /** Extra points for repeat breadth beyond the n=3 base point (diminishing, capped +12). */
    private static function breadthBonus(int $n): int
    {
        return match (true) {
            $n <= 3  => 0,
            $n <= 5  => 3,
            $n <= 10 => 6,
            $n <= 19 => 9,
            default  => 12,
        };
    }

    /** 3 — Questioned Costs / Severity (2 CFR 200 Subpart F): QC + modified opinions + disclosures. */
    public static function questionedCosts(array $latest): array
    {
        $qc = (int) $latest['qc'];
        $mod = (int) $latest['modified'];
        $mnc = (bool) $latest['material_noncompliance'];
        $gc  = (bool) $latest['going_concern'];
        if ($mod >= 2 || ($mnc && $qc >= 2)) $base = 100;
        elseif ($gc)                         $base = 90;
        elseif ($mod >= 1 || $mnc)           $base = 60;
        elseif ($qc >= 3)                    $base = 45;
        elseif ($qc >= 1)                    $base = 20;
        else                                 $base = 0;

        // Dollar-magnitude bump from finding-text questioned costs. ADDITIVE — it never
        // lowers the count base and only fires when a trusted amount was extracted
        // (qc_dollars > 0), so audits without a parsed figure are scored exactly as
        // before. Severity = max(absolute-$ tier, $/expended-ratio tier).
        $dollars  = (int) ($latest['qc_dollars'] ?? 0);
        $expended = (int) ($latest['expended'] ?? 0);
        $bump = 0;
        if ($dollars > 0) {
            foreach (self::QC_DOLLAR_TIERS as [$min, $b]) { if ($dollars >= $min) { $bump = $b; break; } }
            if ($expended > 0) {
                $ratio = $dollars / $expended;
                foreach (self::QC_RATIO_TIERS as [$min, $b]) { if ($ratio >= $min) { $bump = max($bump, $b); break; } }
            }
        }
        return ['score' => self::clamp($base + $bump),
                'qc' => $qc, 'modified' => $mod, 'material_noncompliance' => $mnc, 'going_concern' => $gc,
                'qc_dollars' => $dollars, 'qc_bump' => $bump];
    }

    /** 4 — Reporting Timeliness (2 CFR 200.512): days late + delinquent years in window. */
    public static function reportingTimeliness(array $e, array $latest): array
    {
        $daysLate = $latest['days_late'];
        $onTime = ($daysLate === null) ? true : ((int) $daysLate <= 0);

        // delinquent fiscal years in the most recent 3-year window (filed >90 days late)
        $ly = (int) $e['latest_year'];
        $delinquent = 0;
        $hasPriorYear = isset($e['by_year'][$ly - 1]);
        for ($y = $ly; $y >= $ly - 2; $y--) {
            if (isset($e['by_year'][$y]) && $e['by_year'][$y]['days_late'] !== null
                && (int) $e['by_year'][$y]['days_late'] > self::DELINQUENT_DAYS) {
                $delinquent++;
            }
        }

        if ($onTime && $hasPriorYear) {
            // damped path: prior delinquency does not dominate when the latest filing is clean
            $base = $delinquent >= 2 ? self::TIMELY_DAMP_2 : ($delinquent === 1 ? self::TIMELY_DAMP_1 : 0);
            $damped = $delinquent > 0;
        } else {
            $damped = false;
            if ($delinquent >= 2)            $base = 100;
            elseif ($delinquent === 1)       $base = 85;
            elseif ($daysLate !== null && $daysLate > 365)  $base = 85;
            elseif ($daysLate !== null && $daysLate >= 91)  $base = 55;
            elseif ($daysLate !== null && $daysLate >= 1)   $base = 25;
            else                             $base = 0;
        }
        return ['score' => self::clamp($base),
                'days_late' => $daysLate === null ? null : (int) $daysLate, 'delinquent_years' => $delinquent, 'damped' => $damped];
    }

    /** 5 — Cash & Financial Management (2 CFR 200.302/.305): type B/C/J findings + persistence. */
    public static function cashFinancial(array $e, array $latest): array
    {
        $find = (int) $latest['fin_find'];
        $mw   = (int) $latest['fin_mw'];
        // persistence = lineage depth of the deepest financial (B/C/J) issue (computed
        // upstream like the repeat chain), so a churn of different financial findings
        // year-over-year no longer reads as one persistent problem.
        $persist = (int) ($e['fin_chain'] ?? 0);
        if ($persist >= 3)              $base = 100;
        elseif ($find >= 2 || $mw >= 1) $base = 90;   // breadth, or a financial material weakness
        elseif ($persist === 2)         $base = 85;
        elseif ($find === 1)            $base = 40;
        else                            $base = 0;
        return ['score' => self::clamp($base), 'fin_findings' => $find, 'fin_mw' => $mw, 'persistence' => $persist];
    }

    /** 6 — Subrecipient Monitoring (2 CFR 200.331-.333): type M findings + persistence, gated on pass-through. */
    public static function subrecipient(array $e, array $latest): array
    {
        $find = (int) $latest['sub_find'];
        $mw   = (int) $latest['sub_mw'];
        // persistence = lineage depth of the deepest subrecipient (type M) issue
        $persist = (int) ($e['sub_chain'] ?? 0);
        if ($persist >= 3)      $base = 100;
        elseif ($persist === 2) $base = 85;
        elseif ($mw >= 1)       $base = 75;
        elseif ($find >= 1)     $base = 40;
        else                    $base = 0;   // no pass-through activity, no findings
        return ['score' => self::clamp($base),
                'sub_findings' => $find, 'sub_mw' => $mw, 'persistence' => $persist, 'has_passthrough' => (bool) ($e['has_passthrough'] ?? false)];
    }

    /**
     * Heuristic CAP quality 0-1 for a single planned-action narrative: fraction of
     * {measurable milestone, named owner, parseable date} present. A pure-text proxy
     * for the §200.511(c) elements (FAC exposes no structured CAP fields).
     */
    public static function capQualityScore(string $text): float
    {
        $t = trim($text);
        if ($t === '') return 0.0;
        $hasMilestone = (bool) preg_match('/\d+\s*%|\$\s*\d|\d{1,3}(,\d{3})+|\bphase\s*\d|\bstep\s*\d/i', $t);
        $hasOwner = (bool) preg_match('/\b(director|officer|manager|coordinator|controller|supervisor|department|division|cfo|ceo|comptroller|administrator|accountant|treasurer|bookkeeper|clerk|board|committee)\b/i', $t);
        $hasDate = (bool) preg_match('/\b(20\d\d)\b|\b(q[1-4])\b|\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+\d|\d{1,2}\/\d{1,2}\/\d{2,4}/i', $t);
        return (($hasMilestone ? 1 : 0) + ($hasOwner ? 1 : 0) + ($hasDate ? 1 : 0)) / 3.0;
    }

    /** 7 — CAP Quality / Resolution (2 CFR 200.511(c)): coverage × (0.4 + 0.6 × quality). */
    public static function capQuality(array $latest, array $cap): array
    {
        if ((int) $latest['total_findings'] === 0) {
            return ['score' => 0.0, 'coverage' => null, 'quality' => null, 'composite' => null]; // no findings, no CAP duty
        }
        if (!($cap['available'] ?? true)) {
            return ['score' => null, 'coverage' => null, 'quality' => null, 'composite' => null]; // narrative not yet published
        }
        $coverage = (float) $cap['coverage'];
        $quality  = (float) $cap['quality'];
        $composite = $coverage * (0.4 + 0.6 * $quality);
        if ($composite >= 0.90)      $base = 0;
        elseif ($composite >= 0.70)  $base = 20;
        elseif ($composite >= 0.50)  $base = 45;
        elseif ($composite > 0)      $base = 70;
        else                         $base = 100;  // no CAPs filed against findings
        return ['score' => self::clamp($base),
                'coverage' => round($coverage, 3), 'quality' => round($quality, 3), 'composite' => round($composite, 3)];
    }

    /**
     * Full score for one entity bundle.
     * @return array{subscores:array,composite:float,tier:string,drivers:array}
     */
    public static function compute(array $e): array
    {
        $ly = (int) $e['latest_year'];
        $latest = $e['by_year'][$ly];

        $ic  = self::internalControl($latest);
        $rf  = self::repeatFindings($e, $latest);
        $qc  = self::questionedCosts($latest);
        $rt  = self::reportingTimeliness($e, $latest);
        $cf  = self::cashFinancial($e, $latest);
        $sr  = self::subrecipient($e, $latest);
        $cap = self::capQuality($latest, $e['cap']);

        $sub = [
            'internal_control'     => $ic['score'],
            'repeat_findings'      => $rf['score'],
            'questioned_costs'     => $qc['score'],
            'reporting_timeliness' => $rt['score'],
            'cash_financial'       => $cf['score'],
            'subrecipient'         => $sr['score'],
            'cap_quality'          => $cap['score'],   // may be null (data unavailable)
        ];

        // weighted average over available (non-null) components; renormalize if any is null
        $num = 0.0;
        $den = 0;
        foreach (self::WEIGHTS as $k => $w) {
            if ($sub[$k] === null) continue;
            $num += $sub[$k] * $w;
            $den += $w;
        }
        $composite = $den > 0 ? round($num / $den, 2) : 0.0;

        return [
            'subscores' => $sub,
            'composite' => $composite,
            'tier'      => self::tier($composite),
            'drivers'   => [
                'internal_control' => $ic, 'repeat_findings' => $rf, 'questioned_costs' => $qc,
                'reporting_timeliness' => $rt, 'cash_financial' => $cf, 'subrecipient' => $sr, 'cap_quality' => $cap,
            ],
        ];
    }
}
