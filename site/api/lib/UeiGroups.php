<?php
declare(strict_types=1);

/**
 * Crosswalk (state_uei) UEI successions — who is really ONE recipient.
 *
 * A government that changed UEI keeps a directory row per member, but it is a single recipient
 * with a single filing history. Every surface that judges an entity must collapse the succession
 * the same way, or the RETIRED member reads as an entity that abruptly stopped filing: on
 * 2026-07-15 that put a false delinquent dot on 9 state governments (AR/CO/DC/DE/NC/OH/SC/VI/WV)
 * and, before this helper existed, was about to flag the same 9 as "Likely-required audit not
 * filed" — an accusation for changing UEI.
 *
 * Canonical = the member with the latest active audit year, tie-broken on federal $ — the rule
 * /api/recipients and /api/evaluation already use; this is that rule in one callable place
 * instead of a fourth transcription.
 *
 * Kept OUT of Rules.php on purpose: Rules.php is deliberately DB-free and unit-testable in
 * isolation, and this needs a PDO.
 *
 * NOTE: /api/evaluation and /api/recipients still carry their own group handling, entangled with
 * labels/facets/merged counts. They are verified correct; this helper is for callers that only
 * need "who is canonical, and which members feed their history".
 */
if (!function_exists('aero_uei_groups')) {
    /**
     * @return array{canon: array<string,string[]>, retired: array<string,string>}
     *   canon   — canonical uei => [all member ueis] (multi-UEI groups only)
     *   retired — non-canonical member uei => its canonical uei
     */
    function aero_uei_groups(PDO $pdo): array
    {
        $canon = [];
        $retired = [];
        foreach ($pdo->query("SELECT ueis FROM state_uei") as $r) {
            $set = array_values(array_unique(array_filter(array_map(
                'strtoupper',
                preg_split('/[\s,]+/', trim((string) $r['ueis']), -1, PREG_SPLIT_NO_EMPTY) ?: []
            ))));
            if (count($set) < 2) continue;                       // single-UEI government: nothing to collapse
            $in = implode(',', array_fill(0, count($set), '?'));
            $st = $pdo->prepare("SELECT uei, latest_audit_year ly, COALESCE(federal_latest, 0) fl
                                 FROM entity WHERE uei IN ($in)");
            $st->execute($set);
            $info = [];
            foreach ($st as $e) $info[$e['uei']] = [(int) ($e['ly'] ?? -1), (float) $e['fl']];
            $best = [-1, -1.0]; $winner = null;
            foreach ($set as $u) {
                $cand = $info[$u] ?? [-1, -1.0];
                if ($cand > $best) { $best = $cand; $winner = $u; }
            }
            if ($winner === null) continue;
            $canon[$winner] = $set;
            foreach ($set as $u) if ($u !== $winner) $retired[$u] = $winner;
        }
        return ['canon' => $canon, 'retired' => $retired];
    }
}
