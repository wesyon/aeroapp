<?php
declare(strict_types=1);

/**
 * GET /api/passthrough[?year=YYYY] — fleet-wide pass-through dashboard over subaward_edge
 * (the sanitized prime×sub×year aggregate). One cached payload per year:
 *   years      — per-year totals + HHS share + flag census (trend bars)
 *   kpis       — selected-year totals, flag census, HHS share, top-10-prime concentration
 *   flags      — ⚑ subrecipients: ≥$1M max subaward, Single-Audit-applicable type, NO audit
 *                coverage visible (no FAC filings under the UEI, not a declared additional
 *                UEI, not a curated entity_related_uei component). Same rule as the profile
 *                Passthrough tab. 'maybe_state_component' softens government-type subs fed
 *                by primes in a single state (likely inside that state's statewide audit).
 *   oversight  — the accountability view: PRIMES ranked by how much they passed to flagged
 *                subs (2 CFR 200.332 — subrecipient monitoring is the prime's duty).
 *   corridors  — largest single prime→sub relationships of the year.
 *   top_subs / top_primes — $ leaderboards.
 */

$yearS = q_str('year');
$year = ($yearS !== null && preg_match('/^\d{4}$/', $yearS)) ? (int) $yearS : null;
$FLAG_MIN = 1000000;   // ≥$1M max subaward (2 CFR 200.501 threshold neighborhood)

set_time_limit(180);
$cacheFile = dirname(__DIR__) . '/cache/passthrough_' . ($year ?? 'latest') . '.json';
if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
    $cached = cache_get($cacheFile);
    if ($cached !== null) json_out($cached);   // else: torn/empty cache — fall through and recompute
}

// aero entity_type → the FSRS type VOCABULARY + Single-Audit applicability, so the fallback
// (subs without an FSRS classification) lands in the same buckets as the classified ones —
// otherwise the mix panel shows both vocabularies side by side ("Nonprofit" AND "non-profit").
$aeroAudit = static function (?string $t): array {
    static $map = [
        'state'      => ['Government', 1], 'local'   => ['Government', 1],
        'tribal'     => ['Government', 1], 'higher-ed' => ['Higher Ed', 1],
        'non-profit' => ['Nonprofit', 1],  'unknown' => [null, 0],
    ];
    if ($t === null || $t === '' || !isset($map[$t])) return [null, 0];
    return $map[$t];
};

/** Per-sub aggregation for one year: enrichment + audit-coverage clearing flags. */
$subAgg = static function (int $yr) use ($pdo): array {
    $st = $pdo->prepare(
        "SELECT x.uei, COALESCE(a.display_name, x.sub_name) name,
                x.total, x.max_award, x.subawards, x.primes, x.sample_prime, x.alns,
                x.p_state_min, x.p_state_max,
                a.state, a.entity_type aero_type,
                bt.entity_type bt_type, bt.audit_applicable bt_audit,
                a.has_fac, a.latest_audit_year latest_year,
                (a.latest_audit_year IS NOT NULL) in_hub,
                (au.additional_uei IS NOT NULL) is_addl,
                (er.related_uei IS NOT NULL) is_rel
         FROM (
            SELECT e.sub_vendor_uei uei, MAX(e.sub_name) sub_name,
                   SUM(e.total_amount) total, MAX(e.max_amount) max_award,
                   SUM(e.subawards) subawards,
                   COUNT(DISTINCT e.prime_entity_uei) primes, MAX(e.prime_name) sample_prime,
                   LEFT(GROUP_CONCAT(DISTINCT e.alns SEPARATOR ', '), 300) alns,
                   MIN(pe.state) p_state_min, MAX(pe.state) p_state_max
            FROM subaward_edge e
            LEFT JOIN entity pe ON pe.uei = e.prime_entity_uei
            WHERE e.year = ?
            GROUP BY e.sub_vendor_uei
         ) x
         LEFT JOIN entity a ON a.uei = x.uei
         LEFT JOIN subaward_entity_type bt ON bt.uei = x.uei
         LEFT JOIN (SELECT DISTINCT additional_uei FROM fac_additional_ueis) au ON au.additional_uei = x.uei
         LEFT JOIN (SELECT DISTINCT related_uei FROM entity_related_uei) er ON er.related_uei = x.uei"
    );
    $st->execute([$yr]);
    return $st->fetchAll();
};

/** Apply the ⚑ rule to one aggregated sub row. Returns [isFlag, isSoft, rowArray]. */
$evalRow = static function (array $r) use ($aeroAudit, $FLAG_MIN): array {
    [$aType, $aAudit] = $aeroAudit($r['aero_type']);
    $etype = ($r['bt_type'] !== null && $r['bt_type'] !== '') ? $r['bt_type'] : ($aType ?? 'Unclassified');
    $audit = ($r['bt_type'] !== null && $r['bt_type'] !== '') ? (int) $r['bt_audit'] : $aAudit;
    $alns  = (string) ($r['alns'] ?? '');
    $row = [
        'uei' => $r['uei'], 'name' => $r['name'], 'total' => (float) $r['total'],
        'max_award' => (float) $r['max_award'], 'subawards' => (int) $r['subawards'],
        'primes' => (int) $r['primes'], 'sample_prime' => $r['sample_prime'],
        'state' => $r['state'], 'type' => $etype, 'in_hub' => (int) $r['in_hub'] === 1,
        'latest_year' => $r['latest_year'] !== null ? (int) $r['latest_year'] : null,
        'alns' => $alns !== '' ? $alns : null,
        'hhs' => str_contains($alns, '93.'),
    ];
    $covered = ((int) $r['has_fac'] === 1) || ((int) $r['is_addl'] === 1) || ((int) $r['is_rel'] === 1);
    $isFlag = $audit === 1 && (float) $r['max_award'] >= $FLAG_MIN && !$covered;
    $isSoft = false;
    if ($isFlag) {
        $isSoft = stripos($etype, 'government') !== false
               && $r['p_state_min'] !== null && $r['p_state_min'] === $r['p_state_max']
               && ($r['state'] === null || $r['state'] === $r['p_state_min']);
        $row['maybe_state_component'] = $isSoft;
        $row['prime_state'] = $r['p_state_min'];
    }
    return [$isFlag, $isSoft, $row];
};

// ---- per-year trend (totals + HHS share in one pass; flag census per year below) ----------
$years = [];
foreach ($pdo->query(
    "SELECT year, COUNT(*) edges, COUNT(DISTINCT sub_vendor_uei) subs,
            COUNT(DISTINCT prime_entity_uei) primes, COALESCE(SUM(total_amount),0) total,
            COALESCE(SUM(CASE WHEN alns LIKE '%93.%' THEN total_amount ELSE 0 END),0) hhs_total
     FROM subaward_edge GROUP BY year ORDER BY year"
) as $r) {
    $years[(int) $r['year']] = ['year' => (int) $r['year'], 'edges' => (int) $r['edges'],
        'subs' => (int) $r['subs'], 'primes' => (int) $r['primes'],
        'total' => (float) $r['total'], 'hhs_total' => (float) $r['hhs_total'],
        'flagged' => 0, 'flagged_total' => 0.0];
}
if ($year === null && $years) {
    $cur = (int) gmdate('Y');
    $cands = array_filter(array_keys($years), static fn ($y) => $y < $cur);
    $year = $cands ? max($cands) : max(array_keys($years));
}

// flag census for EVERY year (cheap enough cached; gives the trend bars their ⚑ overlay)
$flags = []; $subsAll = []; $kSoft = 0; $typeMix = [];
foreach (array_keys($years) as $yr) {
    foreach ($subAgg($yr) as $r) {
        [$isFlag, $isSoft, $row] = $evalRow($r);
        if ($isFlag) { $years[$yr]['flagged']++; $years[$yr]['flagged_total'] += $row['total']; }
        if ($yr !== $year) continue;                    // detail lists: selected year only
        $subsAll[] = $row;
        if (!isset($typeMix[$row['type']])) $typeMix[$row['type']] = ['n' => 0, 'total' => 0.0, 'flagged' => 0];
        $typeMix[$row['type']]['n']++; $typeMix[$row['type']]['total'] += $row['total'];
        if ($isFlag) { $flags[] = $row; $typeMix[$row['type']]['flagged']++; if ($isSoft) $kSoft++; }
    }
}
usort($flags, static fn ($a, $b) => $b['total'] <=> $a['total']);
usort($subsAll, static fn ($a, $b) => $b['total'] <=> $a['total']);

// ---- prime detail for every flagged sub: one pair-level pass feeds BOTH the oversight
// ranking (per prime) and each flag row's expandable prime list (per sub) -------------------
$oversight = [];
if ($flags) {
    $ids = array_column($flags, 'uei');
    $agg = []; $bySub = [];
    foreach (array_chunk($ids, 800) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $q = $pdo->prepare(
            "SELECT e.sub_vendor_uei s_uei, e.prime_entity_uei uei,
                    COALESCE(MAX(a.display_name), MAX(e.prime_name)) name,
                    MAX(a.state) state, SUM(e.total_amount) total, SUM(e.subawards) subawards
             FROM subaward_edge e LEFT JOIN entity a ON a.uei = e.prime_entity_uei
             WHERE e.year = ? AND e.sub_vendor_uei IN ($in)
             GROUP BY e.sub_vendor_uei, e.prime_entity_uei"
        );
        $q->execute(array_merge([$year], $chunk));
        foreach ($q as $r) {
            $u = $r['uei'];
            if (!isset($agg[$u])) $agg[$u] = ['uei' => $u, 'name' => $r['name'], 'state' => $r['state'], 'flagged_subs' => 0, 'flagged_total' => 0.0];
            $agg[$u]['flagged_subs']++;
            $agg[$u]['flagged_total'] += (float) $r['total'];
            $bySub[$r['s_uei']][] = ['uei' => $u, 'name' => $r['name'],
                'total' => (float) $r['total'], 'subawards' => (int) $r['subawards']];
        }
    }
    foreach ($flags as &$f) {
        $pl = $bySub[$f['uei']] ?? [];
        usort($pl, static fn ($a, $b) => $b['total'] <=> $a['total']);
        $f['prime_list'] = array_slice($pl, 0, 100);   // effectively all — the drilldown must name every feeder
    }
    unset($f);
    $oversight = array_values($agg);
    usort($oversight, static fn ($a, $b) => $b['flagged_total'] <=> $a['flagged_total']);
    $oversight = array_slice($oversight, 0, 100);
}

// ---- corridors: the year's largest single prime→sub relationships -------------------------
$flagSet = array_flip(array_column($flags, 'uei'));
$cor = $pdo->prepare(
    "SELECT e.prime_entity_uei p_uei, COALESCE(MAX(pa.display_name), MAX(e.prime_name)) p_name,
            e.sub_vendor_uei s_uei, COALESCE(MAX(sa.display_name), MAX(e.sub_name)) s_name,
            (MAX(sa.latest_audit_year) IS NOT NULL) s_in_hub,
            SUM(e.total_amount) total, SUM(e.subawards) subawards,
            LEFT(GROUP_CONCAT(DISTINCT e.alns SEPARATOR ', '), 200) alns
     FROM subaward_edge e
     LEFT JOIN entity pa ON pa.uei = e.prime_entity_uei
     LEFT JOIN entity sa ON sa.uei = e.sub_vendor_uei
     WHERE e.year = ?
     GROUP BY e.prime_entity_uei, e.sub_vendor_uei
     ORDER BY total DESC LIMIT 100"
);
$cor->execute([$year]);
$corridors = array_map(static fn ($r) => [
    'p_uei' => $r['p_uei'], 'p_name' => $r['p_name'],
    's_uei' => $r['s_uei'], 's_name' => $r['s_name'], 's_in_hub' => (int) $r['s_in_hub'] === 1,
    'total' => (float) $r['total'], 'subawards' => (int) $r['subawards'],
    'alns' => $r['alns'], 'flagged' => isset($flagSet[$r['s_uei']]),
    'hhs' => str_contains((string) $r['alns'], '93.'),
], $cor->fetchAll());

// ---- top primes leaderboard ----------------------------------------------------------------
$tp = $pdo->prepare(
    "SELECT e.prime_entity_uei uei, COALESCE(MAX(a.display_name), MAX(e.prime_name)) name,
            SUM(e.total_amount) total, COUNT(DISTINCT e.sub_vendor_uei) subs, MAX(a.state) state
     FROM subaward_edge e LEFT JOIN entity a ON a.uei = e.prime_entity_uei
     WHERE e.year = ? GROUP BY e.prime_entity_uei ORDER BY total DESC LIMIT 100"
);
$tp->execute([$year]);
$topPrimes = array_map(static fn ($r) => [
    'uei' => $r['uei'], 'name' => $r['name'], 'total' => (float) $r['total'],
    'subs' => (int) $r['subs'], 'state' => $r['state'],
], $tp->fetchAll());

$types = [];
foreach ($typeMix as $k => $v) $types[] = ['k' => $k, 'n' => $v['n'], 'total' => $v['total'], 'flagged' => $v['flagged']];
usort($types, static fn ($a, $b) => $b['total'] <=> $a['total']);

$yTot   = $years[$year]['total'] ?? 0.0;
$top10  = array_sum(array_map(static fn ($p) => $p['total'], array_slice($topPrimes, 0, 10)));
$payload = [
    'year'   => $year,
    'years'  => array_values($years),
    'kpis'   => [
        'total' => $yTot, 'subs' => count($subsAll),
        'hhs_total' => $years[$year]['hhs_total'] ?? 0.0,
        'flagged' => count($flags), 'flagged_total' => $years[$year]['flagged_total'] ?? 0.0,
        'maybe_state_component' => $kSoft,
        'top10_share' => $yTot > 0 ? $top10 / $yTot : null,
        'flag_min' => $FLAG_MIN,
    ],
    'types'  => $types,
    'flags'  => array_slice($flags, 0, 500),
    'flags_capped' => count($flags) > 500 ? count($flags) : null,
    'oversight'  => $oversight,
    'corridors'  => $corridors,
    'top_subs'   => array_slice($subsAll, 0, 100),
    'top_primes' => $topPrimes,
];
cache_put($cacheFile, $payload);
json_out($payload);
