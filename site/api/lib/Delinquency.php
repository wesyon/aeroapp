<?php
declare(strict_types=1);

require_once __DIR__ . '/Rules.php';   // aero_deadline9 / aero_filing_status / aero_add_months_clamped

/**
 * PREVIEW — Layers 2-5 of docs/DELINQUENCY_METHODOLOGY.md.
 *
 * This does NOT drive any live surface. `aero_l1_confirmed_by()` in Rules.php is still what the
 * Evaluation, the profile, the map, Search and Signals use. This is the proposed replacement,
 * computed alongside so the two can be compared.
 *
 * Layer 1 (which years are expected) already exists as aero_filing_status(). What is new here:
 *
 *   Layer 2  ESTIMATE what the entity expended in the missing fiscal year, from money — a FLOOR,
 *            because sub-$30k pass-through, property, commodities and insurance in force are all
 *            "expended" under 200.502 and invisible to us. There may be more; never less.
 *   Layer 3  Compare to the trigger IN FORCE for that year, keyed on the fiscal year's START.
 *   Layer 4  Classify by what the evidence supports — never a bare yes/no.
 *
 * Deliberately NOT symmetric: an estimate at or over the trigger licenses "required"; an estimate
 * under it does NOT license "exempt" unless we can affirmatively show nothing could have been
 * expended (no period of performance reaching the year, no pass-through, no live loan balance).
 */
if (!function_exists('aero_trigger_for_fy')) {

    /** 2024 revision cutover: fiscal years BEGINNING on/after this date use the higher trigger. */
    define('AERO_TRIGGER_CUTOVER', '2024-10-01');
    define('AERO_TRIGGER_LOW', 750000.0);      // FYs beginning before the cutover
    define('AERO_TRIGGER_HIGH', 1000000.0);    // FYs beginning on/after it

    /**
     * The Single Audit trigger in force for the fiscal year ENDING on $fyEnd (2 CFR 200.501).
     *
     * Keyed on when the fiscal year BEGAN, never on its label. A 30-June entity's "FY2025" ran
     * 2024-07-01..2025-06-30 — it began three months before the cutover, so it is still on $750k.
     * 30-June is 54% of recipients, so getting this wrong would mis-trigger most of the population.
     */
    function aero_trigger_for_fy(string $fyEnd): float
    {
        // FY start = one year back, plus a day (the day after the prior year-end).
        $start = date('Y-m-d', strtotime('+1 day', aero_add_months_clamped($fyEnd, -12)));
        return $start >= AERO_TRIGGER_CUTOVER ? AERO_TRIGGER_HIGH : AERO_TRIGGER_LOW;
    }

    /**
     * Classify ONE missing fiscal year.
     *
     * @param array $c context:
     *   fy_end        'Y-m-d'  the missing year's fiscal-year end
     *   estimate      float    observed floor: outlays + subawards + loans (+ PoP-allocated fallback)
     *   money_ok      bool     Layer 0 verdict — false means the money describes another entity
     *   filed_before  ?float   SEFA of the nearest filed year BEFORE this one (null if none)
     *   filed_after   ?float   SEFA of the nearest filed year AFTER this one (null if none)
     *   prior_sefa    float    the entity's most recent filed expenditure figure
     *   pop_reaches   bool     any award period of performance reaching this fiscal year
     *   has_subaward  bool     any pass-through in/near the window
     *   has_loan      bool     any outstanding loan balance (200.502(b) counts it every year)
     *   covered       bool     DID WE FIND THIS RECIPIENT'S AWARDS? Not "did the crawler run" —
     *                          we must hold actual award records for them. Without this, "no period
     *                          of performance" is indistinguishable from "we never found them", and
     *                          R5 declares an entity exempt on an absence of effort rather than an
     *                          absence of money. The State of Nevada HAD been crawled yet has zero
     *                          award rows (its money is filed under other UEIs) — a crawl that
     *                          returns nothing for a $9.7B recipient is a data defect, not evidence
     *                          of wind-down.
     *
     *   parent_audited bool    a PARENT auditee (this UEI is a declared component of it, via
     *                          fac_additional_ueis / entity_related_uei) FILED an accepted audit
     *                          for THIS fiscal year — so this component's federal spending is
     *                          audited inside the parent's consolidated report, and the component
     *                          is not separately delinquent. The inverse of the money rollup.
     *
     * Money floors, computed by the caller (Layer 2), separating SPENT from COMMITTED — and, within
     * committed, PoP-IN-FY from ALLOCATED:
     *   est_outlay      = outlays + pass-through + loans         — money we can see DISBURSED
     *   est_oblig_local = obligations from awards whose PoP is WITHIN this FY + PT + loans
     *                     — committed money that genuinely belongs to this year (no allocation guess)
     *   est_oblig       = ALL PoP-allocated obligations + PT + loans
     *                     — includes straight-line slices of multi-year awards (approximated)
     * Grades, by descending confidence: Observed (spent) > Bracketed > Committed (est_oblig_local,
     * committed FOR this year) > Allocated (est_oblig, a slice of multi-year money) > Persistent.
     * The split is at the client's request, to observe how often each occurs; may be reconsidered.
     * (Back-compat: 'estimate' stands in for est_outlay; est_oblig_local defaults to est_oblig.)
     *
     * @return string covered|observed|bracketed|committed|allocated|persistent|exempt|indeterminate
     */
    function aero_delinquency_classify(array $c): string
    {
        $trigger    = aero_trigger_for_fy((string) $c['fy_end']);
        $outlay     = (float) ($c['est_outlay'] ?? $c['estimate'] ?? 0);
        $oblig      = (float) ($c['est_oblig'] ?? 0);
        $obligLocal = (float) ($c['est_oblig_local'] ?? $c['est_oblig'] ?? 0);

        // R0 — COVERED: a parent's consolidated audit already covers this year. Checked FIRST, above
        // every finding grade: if the component's money is audited in the parent's report, the
        // component is not delinquent no matter how much money it received. This is the exact inverse
        // of the component money rollup — it prevents flagging a component that IS being audited, just
        // under its parent's UEI.
        if (!empty($c['parent_audited'])) {
            return 'covered';
        }
        // R1 — OBSERVED: money we can see was SPENT clears the bar. The strongest finding. Requires
        // trustworthy attribution: a quarantined UEI's dollars belong to someone else.
        if (!empty($c['money_ok']) && $outlay >= $trigger) {
            return 'observed';
        }
        // R2 — BRACKETED: filed over the trigger on BOTH sides of the gap. Near-certain — rests on
        // audited SEFA (the entity's own reported figure), so it survives a money quarantine.
        if ($c['filed_before'] !== null && $c['filed_after'] !== null
            && (float) $c['filed_before'] >= $trigger && (float) $c['filed_after'] >= $trigger) {
            return 'bracketed';
        }
        // R3 — COMMITTED (PoP in FY): obligations from awards whose period of performance falls WITHIN
        // this fiscal year clear the bar. The whole obligation genuinely belongs to this year — no
        // allocation guess — so it is the strong committed case, nearly as good as Observed. Committed
        // money, not confirmed spend, so still below Bracketed's audited SEFA.
        if (!empty($c['money_ok']) && $obligLocal >= $trigger) {
            return 'committed';
        }
        // R4 — ALLOCATED: only the FULL PoP-allocated obligation clears it — i.e. a straight-line
        // slice of a longer multi-year award. Weakest of the money-confirmed grades: committed (not
        // spent) AND approximated by even-spread allocation. The dollars-at-risk figure here is an
        // estimate, not a measurement (though the >= trigger call is usually robust — it clears wide).
        if (!empty($c['money_ok']) && $oblig >= $trigger) {
            return 'allocated';
        }
        // R5 — PERSISTENT: trailing silence, but they were over the line and are still receiving. An
        // inference from history, not evidence about THIS year.
        if ((float) $c['prior_sefa'] >= $trigger && (!empty($c['pop_reaches']) || !empty($c['has_subaward']))) {
            return 'persistent';
        }
        // R7 — EXEMPT: affirmatively nothing could have been expended.
        //
        // 'covered' is the precondition and it is not optional: this claim rests entirely on
        // ABSENCE, so it is only meaningful if we actually searched. The award crawl is currently
        // FAC-seeded, so an entity nobody crawled has no period of performance either — and
        // without this guard R5 called the State of Nevada (last SEFA $9.7B) and Johns Hopkins
        // ($3.8B) "exempt", on no evidence whatsoever. 94.9% of the exempt bucket was that.
        //
        // The loan clause is equally load-bearing: 200.502(b) counts an outstanding balance as
        // expended EVERY year until repaid, so an entity with no flows can still be required.
        if (!empty($c['covered'])
            && empty($c['pop_reaches']) && empty($c['has_subaward']) && empty($c['has_loan'])) {
            return 'exempt';
        }
        // R6 — INDETERMINATE: we cannot tell. A gap in our data, NOT a finding about the entity.
        return 'indeterminate';
    }

    /**
     * Dollars at risk, for ranking: what we can see, else what they last reported.
     *
     * MUST respect the Layer 0 quarantine. Gating only the classification is not enough — the
     * number printed beside it is what a reader acts on. Brazoria County Association for Children's
     * Habilitation (real SEFA $761,335) carries $314B of misattributed Texas Medicaid; ranked on
     * that estimate it sorts FIRST, which is precisely the failure the quarantine exists to stop.
     * If the money is not this entity's, it cannot size this entity's exposure.
     */
    function aero_delinquency_exposure(array $c): float
    {
        $prior = (float) ($c['prior_sefa'] ?? 0);
        if (empty($c['money_ok'])) return $prior;              // untrusted dollars rank nothing
        $est = (float) ($c['estimate'] ?? 0);
        return $est > 0 ? $est : $prior;
    }

    /** Human-readable basis line — the cited evidence behind a classification. */
    function aero_delinquency_basis(array $c, string $class): string
    {
        $t = aero_trigger_for_fy((string) $c['fy_end']);
        $money = sprintf('outlays $%s + pass-through $%s + loans $%s',
            number_format((float) ($c['outlays'] ?? 0)), number_format((float) ($c['subawards'] ?? 0)),
            number_format((float) ($c['loans'] ?? 0)));
        $oblig = sprintf('PoP-allocated obligations $%s + pass-through $%s + loans $%s',
            number_format((float) ($c['pop_oblig'] ?? 0)), number_format((float) ($c['subawards'] ?? 0)),
            number_format((float) ($c['loans'] ?? 0)));
        return match ($class) {
            'covered'       => 'audited within a parent entity\'s consolidated Single Audit for this year',
            'observed'      => sprintf('SPENT: %s = $%s, clears trigger $%s', $money,
                                        number_format((float) ($c['est_outlay'] ?? $c['estimate'] ?? 0)), number_format($t)),
            'committed'     => sprintf('COMMITTED for this year (award period within FY, not confirmed spent): %s = $%s, clears trigger $%s',
                                        $oblig, number_format((float) ($c['est_oblig_local'] ?? 0)), number_format($t)),
            'allocated'     => sprintf('ALLOCATED (straight-line slice of a multi-year obligation; approximate): %s = $%s, clears trigger $%s',
                                        $oblig, number_format((float) ($c['est_oblig'] ?? 0)), number_format($t)),
            'bracketed'     => sprintf('filed either side at $%s and $%s, both over trigger $%s',
                                        number_format((float) $c['filed_before']),
                                        number_format((float) $c['filed_after']), number_format($t)),
            'persistent'    => sprintf('last filed $%s (over trigger $%s) and still receiving — inference, not evidence for this year',
                                        number_format((float) $c['prior_sefa']), number_format($t)),
            'exempt'        => 'no award period reaches this year, no pass-through, no live loan balance',
            default         => sprintf('no usable money evidence (%s) — data gap, not a finding',
                                        empty($c['money_ok']) ? 'attribution quarantined' : 'nothing observed'),
        };
    }
}
