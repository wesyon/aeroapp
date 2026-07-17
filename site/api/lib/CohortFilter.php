<?php
declare(strict_types=1);

/**
 * Shared cohort filtering for dashboard routes (delinquency, opinions, ...).
 *
 * "State Govt" is SYNTHETIC — the state_uei registry governments (50 states + DC, territories
 * rolled in per the client 2026-07-07), matched by UEI, not entity_type: they sit under
 * entity_type='state' alongside their agencies, so a raw-type filter can never isolate them.
 * 'state' therefore means "State (other entities)" = entity_type 'state' MINUS the registry.
 * Same split recipients.php makes; the frontends label via the shared TYPE_OPTS, so the
 * dropdowns read identically everywhere.
 */
if (!function_exists('aero_registry_ueis')) {

    /** All state_uei registry member UEIs (uppercased), one small query. */
    function aero_registry_ueis(PDO $pdo): array
    {
        $out = [];
        foreach ($pdo->query("SELECT ueis FROM state_uei") as $r) {
            foreach (preg_split('/\s+/', trim((string) $r['ueis'])) as $u) {
                if ($u !== '') $out[strtoupper($u)] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * Append the entity-type condition to $conds/$params.
     * @param string $ueiCol  fully-qualified UEI column of the row being filtered (e.g. 'd.uei')
     * @param string $typeCol fully-qualified entity_type column (e.g. 'e.entity_type')
     */
    function aero_etype_cond(string $etype, array $registry, string $ueiCol, string $typeCol,
                             array &$conds, array &$params): void
    {
        if ($etype === 'stategov') {
            if ($registry) {
                $conds[] = "$ueiCol IN (" . implode(',', array_fill(0, count($registry), '?')) . ')';
                array_push($params, ...$registry);
            } else { $conds[] = '1=0'; }
        } elseif ($etype === 'state') {
            $conds[] = "$typeCol = 'state'";
            if ($registry) {
                $conds[] = "$ueiCol NOT IN (" . implode(',', array_fill(0, count($registry), '?')) . ')';
                array_push($params, ...$registry);
            }
        } else {
            $conds[] = "$typeCol = ?";
            $params[] = $etype;
        }
    }
}
