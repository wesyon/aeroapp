<?php
declare(strict_types=1);

/**
 * AERO — repeat-finding recurrence-depth kernel (pure; no DB, no I/O).
 *
 * Single home for the "walk prior_finding_ref_numbers, count distinct audit years,
 * apply boundary credit, floor at 2" algorithm that was hand-copied across the
 * scorer (compute_scores.php) and the evaluation / repeat / grantee / finding
 * routes — and had drifted: the scorer omitted boundary credit, hop ceilings were
 * 8 / 9 / 12, and some copies lacked the cycle guard. Canonical rule here is the
 * read-side (Evaluation) semantics, so the score agrees with the Evaluation levels
 * by construction:
 *   - boundary credit ON  (a flagged repeat whose deepest loaded node still cites a
 *     prior we can't load counts +1 beyond the traced chain — honoring the auditor's
 *     is_repeat flag for history that predates our FY2022+ window),
 *   - cycle guard ON,
 *   - one generous hop ceiling, and
 *   - the prior map keyed for REPEAT findings only.
 *
 * Operates on maps the caller has already built for one entity (or merged UEI group):
 *   refYear     [ref => audit_year]      every loaded finding in scope
 *   prior       [ref => first-prior-ref] repeats only (aero_first_prior); '' / absent otherwise
 *   rep         [ref => 0|1]             is_repeat_finding
 *   priorYears  [ref => [year, ...]]     optional; auditor-enumerated years (aero_prior_years)
 *   windowStart int|null                 optional; entity's earliest loaded audit year (for trace reasons)
 *
 * Unit-tested in api/tests/lineage_test.php.
 */
final class Lineage
{
    /** Max prior-ref hops. Set well above the largest audit-year span we ingest
     *  (FY2022+), so a loaded chain is never truncated — replaces the inline walks'
     *  drifted 8 / 9 / 12 ceilings with one value. */
    public const HOPS = 20;

    /**
     * Recurrence depth + lineage detail for one finding.
     *
     * @param string   $ref      target finding reference
     * @param array    $maps     refYear / prior / rep / priorYears / windowStart (see class doc)
     * @param int|null $asOfYear when set, only COUNT chain years <= it (the level-migration
     *                           "as of a prior audit" view); the target's own year is always counted
     * @return array{
     *   is_repeat:bool, traced_depth:int, documented_depth:int, loaded_years:list<int>,
     *   documented_years:list<int>, verified:array<int,string>, deep_ref:string,
     *   deep_year:?int, boundary_credited:bool, break:?array
     * }
     */
    public static function walk(string $ref, array $maps, ?int $asOfYear = null): array
    {
        $refYear     = $maps['refYear'] ?? [];
        $prior       = $maps['prior'] ?? [];
        $rep         = $maps['rep'] ?? [];
        $priorYears  = $maps['priorYears'] ?? [];
        $windowStart = $maps['windowStart'] ?? null;

        $isRepeat = ((int) ($rep[$ref] ?? 0)) === 1;
        $floor    = $isRepeat ? 2 : 1;   // a flagged repeat is >= 2 even if its prior predates our window

        if (!isset($refYear[$ref])) {     // ref not in scope: a minimal, well-formed result
            return [
                'is_repeat' => $isRepeat, 'traced_depth' => $floor, 'documented_depth' => $floor,
                'loaded_years' => [], 'documented_years' => [], 'verified' => [], 'deep_ref' => $ref,
                'deep_year' => null, 'boundary_credited' => false, 'break' => null,
            ];
        }

        $start    = (int) $refYear[$ref];
        $years    = [$start => true];     // distinct LOADED audit years (no boundary credit)
        $verified = [$start => $ref];     // year => ref (the traced timeline)
        $docYears = [$start => true];     // loaded years UNION auditor-enumerated years
        foreach ($priorYears[$ref] ?? [] as $y) $docYears[(int) $y] = true;

        // hop backward through first-prior refs, collecting distinct years; the cycle
        // guard ($seen) and HOPS ceiling both terminate pathological data.
        $cur = $ref; $seen = [];
        for ($h = 0; $h < self::HOPS
                     && isset($prior[$cur]) && $prior[$cur] !== ''
                     && isset($refYear[$prior[$cur]]) && !isset($seen[$cur]); $h++) {
            $seen[$cur] = true;
            $cur = $prior[$cur];
            $cy  = (int) $refYear[$cur];
            if ($asOfYear === null || $cy <= $asOfYear) {
                $years[$cy]    = true;
                $verified[$cy] = $cur;
                $docYears[$cy] = true;
                foreach ($priorYears[$cur] ?? [] as $y) $docYears[(int) $y] = true;
            }
        }

        $deepYear = (int) $refYear[$cur];
        $bp = $prior[$cur] ?? '';
        // boundary credit: the deepest reached node cites a prior we can't load (it
        // predates our window). Under the repeats-only prior contract, a non-empty
        // prior[cur] already implies rep[cur]=1, so no separate repeat check is needed.
        $boundary = $bp !== '' && !isset($refYear[$bp])
                    && ($asOfYear === null || $deepYear <= $asOfYear);

        $tracedDepth = max($floor, count($years) + ($boundary ? 1 : 0));
        $docDepth    = max($tracedDepth, count($docYears));   // documented is never below traced

        ksort($years);
        ksort($docYears);
        return [
            'is_repeat'         => $isRepeat,
            'traced_depth'      => $tracedDepth,
            'documented_depth'  => $docDepth,
            'loaded_years'      => array_keys($years),
            'documented_years'  => array_keys($docYears),
            'verified'          => $verified,
            'deep_ref'          => $cur,
            'deep_year'         => $deepYear,
            'boundary_credited' => $boundary,
            'break'             => self::breakAt($cur, $deepYear, $prior, $refYear, $rep, $windowStart, $asOfYear),
        ];
    }

    /**
     * Why the chain stops at its deepest reached node $deepRef — the lineage "break"
     * shown on the finding detail timeline (and the source of grantee's prior_oow).
     * Set only when the deepest node is a flagged repeat asserting an earlier prior:
     *   before_window  — cited prior predates our earliest loaded year (trace ends; a coverage limit)
     *   unresolved_ref — cited prior should be in reach but isn't loaded (a genuine chain break)
     *   not_earlier    — cited prior resolves to the same/later year (a source quirk)
     * Returns null when the chain genuinely originates in-window (no earlier claim).
     */
    private static function breakAt(string $deepRef, int $deepYear, array $prior, array $refYear,
                                    array $rep, ?int $windowStart, ?int $asOfYear): ?array
    {
        if (((int) ($rep[$deepRef] ?? 0)) !== 1) return null;
        $bp = $prior[$deepRef] ?? '';
        if ($bp === '') return null;
        if ($asOfYear !== null && $deepYear > $asOfYear) return null;
        $bpYear = preg_match('/(19|20)\d{2}/', $bp, $m) ? (int) $m[0] : null;
        if (!isset($refYear[$bp])) {
            $reason = ($bpYear !== null && $windowStart !== null && $bpYear < $windowStart)
                ? 'before_window' : 'unresolved_ref';
            return ['year' => $deepYear, 'reason' => $reason, 'prior_ref' => $bp, 'prior_year' => $bpYear];
        }
        if ((int) $refYear[$bp] >= $deepYear) {
            return ['year' => $deepYear, 'reason' => 'not_earlier', 'prior_ref' => $bp, 'prior_year' => (int) $refYear[$bp]];
        }
        return null;   // prior is loadable and earlier (the loop would have followed it) — no break
    }

    /**
     * Presentation timeline for one finding's recurrence chain (oldest -> newest), for the
     * UI's LineageChain component. Wraps walk() and classifies each year:
     *   verified   — a loaded finding we traced through
     *   gap        — an audit the entity filed but this finding wasn't carried (lapse-and-return)
     *   lookback   — the +1 boundary-credit prior the deepest node cites (counted, not verified)
     *   documented — a year the auditor names in the prior-ref list, before our records
     * $filed = [audit_year => any] for the entity (its loaded active audit years).
     * @return array{is_repeat:bool, window_start:?int, traced_depth:int, documented_depth:int, nodes:array, break:?array}
     */
    public static function nodes(string $ref, array $maps, array $filed): array
    {
        $windowStart = $filed ? min(array_keys($filed)) : null;
        $refYear = $maps['refYear'] ?? [];
        if (!isset($refYear[$ref])) {
            return ['is_repeat' => false, 'window_start' => $windowStart,
                    'traced_depth' => 1, 'documented_depth' => 1, 'nodes' => [], 'break' => null];
        }
        $maps['windowStart'] = $windowStart;
        $lin = self::walk($ref, $maps);
        $verified = $lin['verified'];
        $deepYear = $lin['deep_year'];
        $break    = $lin['break'];
        // +1 boundary-credit "lookback" year (counted in traced depth on the auditor's flag, not verified)
        $lookbackYear = ($break && in_array($break['reason'], ['before_window', 'unresolved_ref'], true)) ? $break['prior_year'] : null;

        $nodes = [];
        $docOnly = array_filter($lin['documented_years'], fn ($y) => $y < $deepYear && !isset($verified[$y]));
        if ($lookbackYear !== null && $lookbackYear < $deepYear) $docOnly[] = $lookbackYear;
        $docOnly = array_values(array_unique($docOnly));
        sort($docOnly);
        foreach ($docOnly as $y) $nodes[] = ['year' => $y, 'ref' => null, 'status' => ($y === $lookbackYear ? 'lookback' : 'documented')];
        for ($y = $deepYear; $y <= $refYear[$ref]; $y++) {
            if (isset($verified[$y]))  $nodes[] = ['year' => $y, 'ref' => $verified[$y], 'status' => 'verified'];
            elseif (isset($filed[$y])) $nodes[] = ['year' => $y, 'ref' => null, 'status' => 'gap'];
        }
        return ['is_repeat' => $lin['is_repeat'], 'window_start' => $windowStart,
                'traced_depth' => $lin['traced_depth'], 'documented_depth' => $lin['documented_depth'],
                'nodes' => $nodes, 'break' => $break];
    }

    /**
     * Traceability of a finding's OWN immediate prior (the repeat dashboard's per-finding
     * "trace" category) — distinct from walk()'s break, which describes the deepest node.
     *   ok | before_window | unresolved_ref | not_earlier | no_prior
     * @return array{cat:string, traced:bool}
     */
    public static function firstHopTrace(string $ref, array $maps): array
    {
        $refYear     = $maps['refYear'] ?? [];
        $prior       = $maps['prior'] ?? [];
        $windowStart = $maps['windowStart'] ?? null;

        $fp = $prior[$ref] ?? '';
        if ($fp === '') return ['cat' => 'no_prior', 'traced' => false];
        if (!isset($refYear[$fp])) {
            $fpYear = preg_match('/(19|20)\d{2}/', $fp, $m) ? (int) $m[0] : null;
            $cat = ($fpYear !== null && $windowStart !== null && $fpYear < $windowStart)
                ? 'before_window' : 'unresolved_ref';
            return ['cat' => $cat, 'traced' => false];
        }
        if (isset($refYear[$ref]) && (int) $refYear[$fp] >= (int) $refYear[$ref]) {
            return ['cat' => 'not_earlier', 'traced' => false];
        }
        return ['cat' => 'ok', 'traced' => true];
    }
}
