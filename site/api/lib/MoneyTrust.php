<?php
declare(strict_types=1);

/**
 * LAYER 0 — is the USAspending money attached to a UEI actually THIS entity's money?
 *
 * Every dollar-based test (delinquency estimates, exposure ranking, Signals) keys on UEI and
 * assumes FAC and USAspending mean the same organisation by it. Measured 2026-07-15, they do not
 * always agree, and the disagreement is not small:
 *
 *   G6JLG3FANUA9 — FAC: "Brazoria County Assn for Children's Habilitation" (SEFA $761,335)
 *                  USAspending: "HEALTH & HUMAN SVC COMMN TX" ($314.3B of Texas Medicaid)
 *
 * That is 412,830x the entity's own demonstrated scale. Ranking by dollars puts it first — a
 * $44B "lead" that is really a $761k nonprofit. The dominant cause is a COMPONENT filing its SEFA
 * under its PARENT's UEI (The Preuss School under UCSD, Unitrans under UC Davis), so the UEI's
 * award portfolio belongs to the parent while its auditee is the component.
 *
 * This is NOT a fuzzy-matching artefact: sync_usa.php discards any award whose Recipient UEI is not
 * an exact match. Both systems are internally consistent and contradict each other.
 *
 * Two independent checks, both from data we already store:
 *
 *   NAME  — does USAspending's recipient_name for the UEI share any meaningful token with FAC's
 *           auditee name? Deliberately generous (token overlap, not equality): "State of
 *           Connecticut Clean Water" vs "DEPT OF ENERGY & ENVIRONMENTAL" is the same body named
 *           differently, and a strict test would quarantine the whole registry. False negatives
 *           here are fine — the SCALE check is the backstop.
 *
 *   SCALE — lifetime obligations vs one year's SEFA. Calibrated, not guessed: p50 = 1.0x,
 *           p95 = 8.8x, p99 = 15.7x, p99.5 = 28.9x. The real distribution is over by ~30x, so
 *           SUSPECT_RATIO = 50 sits clear of it and catches 41 entities (0.29%).
 *
 * A quarantined UEI is NOT a finding about the entity — it is a statement that our money evidence
 * for it is unusable. Callers must exclude it from dollar tests rather than flag it.
 */
if (!function_exists('aero_money_trust')) {

    // define(), not const: a const declaration is illegal inside a conditional block, and the
    // function_exists() guard is what makes this file safe to require more than once.
    /** lifetime-obligations : one-year-SEFA ratio beyond which the attribution is implausible. */
    define('AERO_TRUST_SUSPECT_RATIO', 50.0);
    /** Ignore the ratio below this much money — a tiny entity can't be pushed over any threshold. */
    define('AERO_TRUST_MIN_OBLIGATION', 1000000.0);

    /** Normalize an organisation name to comparable tokens (drops punctuation + legal noise). */
    function aero_trust_norm(?string $s): string
    {
        $s = strtoupper(trim((string) $s));
        $s = preg_replace('/[^A-Z0-9 ]+/', ' ', $s);
        $s = preg_replace('/\b(THE|INC|INCORPORATED|LLC|CORP|CORPORATION|CO|COMPANY|LTD|OF|AND|A)\b/', ' ', (string) $s);
        return trim(preg_replace('/\s+/', ' ', (string) $s));
    }

    /**
     * Do two org names plausibly denote the same body? Generous by design — see the file docblock.
     * Unknown on either side returns TRUE: absence of a name is not evidence of a mismatch.
     */
    function aero_trust_names_agree(?string $a, ?string $b): bool
    {
        $a = aero_trust_norm($a);
        $b = aero_trust_norm($b);
        if ($a === '' || $b === '') return true;
        if ($a === $b || str_contains($a, $b) || str_contains($b, $a)) return true;
        $ta = array_filter(explode(' ', $a), static fn ($t) => strlen($t) > 3);
        $tb = array_filter(explode(' ', $b), static fn ($t) => strlen($t) > 3);
        if (!$ta || !$tb) return true;                       // nothing distinctive to compare
        return (bool) array_intersect($ta, $tb);
    }

    /**
     * Verdict per UEI on whether its USAspending money is usable as evidence about that entity.
     *
     * @return array uei => [
     *     'verdict' => 'ok' | 'suspect_name' | 'suspect_scale' | 'suspect_both',
     *     'fac_name' => string, 'usa_name' => string,
     *     'sefa' => float, 'obligations' => float, 'ratio' => float,
     *   ]
     *   Only UEIs that HAVE both a FAC identity and USAspending awards are returned; callers
     *   should treat an absent UEI as "no money evidence", which is a different thing from
     *   "untrusted money evidence".
     */
    function aero_money_trust(PDO $pdo): array
    {
        $out = [];
        $sql = "SELECT e.uei, e.display_name fac_name, COALESCE(e.federal_latest, 0) sefa,
                       MAX(a.recipient_name) usa_name, COALESCE(SUM(a.total_obligation), 0) obl
                FROM entity e
                JOIN usa_award a ON a.recipient_uei = e.uei
                GROUP BY e.uei, e.display_name, e.federal_latest";
        foreach ($pdo->query($sql) as $r) {
            $sefa = (float) $r['sefa'];
            $obl  = (float) $r['obl'];
            $ratio = $sefa > 0 ? $obl / $sefa : ($obl > 0 ? INF : 0.0);

            $nameBad = !aero_trust_names_agree($r['fac_name'], $r['usa_name']);
            // Scale only accuses when the money is BOTH large and wildly out of proportion.
            $scaleBad = $obl >= AERO_TRUST_MIN_OBLIGATION
                && ($sefa <= 0 ? false : $ratio > AERO_TRUST_SUSPECT_RATIO);

            $verdict = $nameBad && $scaleBad ? 'suspect_both'
                     : ($nameBad ? 'suspect_name' : ($scaleBad ? 'suspect_scale' : 'ok'));

            $out[$r['uei']] = [
                'verdict'     => $verdict,
                'fac_name'    => (string) $r['fac_name'],
                'usa_name'    => (string) $r['usa_name'],
                'sefa'        => $sefa,
                'obligations' => $obl,
                'ratio'       => $ratio,
            ];
        }
        return $out;
    }

    /** Convenience: may a dollar test rely on this UEI's USAspending money? */
    function aero_money_trusted(array $trust, string $uei): bool
    {
        return ($trust[$uei]['verdict'] ?? 'ok') === 'ok';
    }
}
