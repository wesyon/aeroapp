<?php
declare(strict_types=1);
/**
 * GET /api/geo_points — recipients as geographic-map dots, placed by ZIP centroid and
 * colored client-side by latest-audit severity (material weakness > repeat > questioned
 * costs > clean), sized by federal $. Three modes:
 *
 *   ?state=XX            — all recipients in a state (registry drill-down)
 *   ?type=<cat>          — that entity type, NATIONWIDE but RISK-CAPPED to top N (severity,
 *                          then federal $) so the map stays readable for huge cohorts
 *   ?type=<cat>&state=XX — ALL of that type in that state (no cap; the scope is already small)
 *
 * Reads the precomputed entity_map_point table (api/sync/build_entity_map_point.php) — modal-ZIP
 * centroid + latest-audit severity flags per entity — so this is a fast indexed read rather than
 * a per-call aggregation. Returns an empty set until that table is built.
 */

$state = q_str('state');
$type  = q_str('type');
if ($state !== null && !preg_match('/^[A-Za-z]{2}$/', $state)) $state = null;
if ($state !== null) $state = strtoupper($state);
if ($state === null && $type === null) {
    json_out(['state' => null, 'type' => null, 'count' => 0, 'rows' => []]);
}

// Fast indexed read off the precomputed entity_map_point joined to the entity hub; filters and
// ordering use the authoritative entity columns (state = latest active audit's auditee_state).
$where = ['e.latest_audit_year IS NOT NULL'];
$params = [];
if ($state !== null) { $where[] = 'e.state = ?'; $params[] = $state; }
if ($type !== null) {
    // entity-type cohorts map straight to entity.entity_type
    $where[] = 'e.entity_type = ?';
    $params[] = $type;
    // the 'state' facet means "state-level OTHER entities" — exclude the crosswalked
    // state/territory governments (those are the registry facets, mirrors recipients.php)
    if ($type === 'state') {
        $reg = [];
        foreach ($pdo->query('SELECT ueis FROM state_uei') as $r) {
            foreach (preg_split('/[\s,]+/', (string) $r['ueis']) as $u) {
                $u = strtoupper(trim($u));
                if ($u !== '') $reg[$u] = true;
            }
        }
        if ($reg) {
            $where[] = 'e.uei NOT IN (' . implode(',', array_fill(0, count($reg), '?')) . ')';
            array_push($params, ...array_keys($reg));
        }
    }
}

// nationwide (no state) cohorts are capped to the highest-signal entities so the SVG dot
// layer stays performant; a state-scoped query returns everything (already small).
$capped = ($state === null && $type !== null);
$limit  = $capped ? q_int('limit', 1200, 1, 4000) : 20000;

if ($capped) {
    // Nationwide cohorts: a severity-weighted per-level budget so the map shows the whole
    // L1–L7 spectrum, not just L1 (which alone far exceeds the cap). Severe levels get more
    // of the budget; clean (lvl 0) is excluded (it's hidden client-side anyway).
    $w = [1 => 7, 2 => 6, 3 => 5, 4 => 4, 5 => 3, 6 => 2, 7 => 1];
    $sw = array_sum($w);
    $cap = 'CASE s.lvl';
    foreach ($w as $lv => $wt) $cap .= " WHEN $lv THEN " . max(1, (int) round($limit * $wt / $sw));
    $cap .= ' ELSE 0 END';
    $sql = "SELECT uei, name, lat, lng, federal, mw, rp, qc, lvl FROM (
                SELECT e.uei AS uei, COALESCE(NULLIF(e.display_name, ''), e.legal_name, e.uei) AS name,
                       m.lat, m.lng, e.federal_latest AS federal, m.mw, m.rp, m.qc, m.lvl,
                       ROW_NUMBER() OVER (PARTITION BY m.lvl ORDER BY e.federal_latest DESC) AS rn
                FROM entity e JOIN entity_map_point m ON m.uei = e.uei
                WHERE " . implode(' AND ', $where) . " AND m.lvl > 0
            ) s
            WHERE s.rn <= ($cap)
            ORDER BY s.lvl, s.federal DESC
            LIMIT $limit";
} else {
    $sql = "SELECT e.uei,
                   COALESCE(NULLIF(e.display_name, ''), e.legal_name, e.uei) AS name,
                   m.lat, m.lng, e.federal_latest AS federal, m.mw, m.rp, m.qc, m.lvl
            FROM entity e
            JOIN entity_map_point m ON m.uei = e.uei
            WHERE " . implode(' AND ', $where) . "
            ORDER BY (CASE WHEN m.lvl = 0 THEN 99 ELSE m.lvl END), e.federal_latest DESC
            LIMIT $limit";
}

try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
} catch (Throwable $e) {
    error_log('geo_points: ' . $e->getMessage());
    json_out(['state' => $state, 'type' => $type, 'count' => 0, 'rows' => [], 'note' => 'entity_map_point unavailable']);
}

$rows = array_map(static function ($r) {
    return [
        'uei'     => $r['uei'],
        'name'    => $r['name'],
        'lat'     => (float) $r['lat'],
        'lng'     => (float) $r['lng'],
        'federal' => $r['federal'] !== null ? (float) $r['federal'] : 0,
        'mw'      => (int) $r['mw'],
        'rp'      => (int) $r['rp'],
        'qc'      => (int) $r['qc'],
        'lvl'     => (int) $r['lvl'],   // precomputed enforcement top_level (0 = clean)
    ];
}, $st->fetchAll());

json_out(['state' => $state, 'type' => $type, 'capped' => $capped, 'count' => count($rows), 'rows' => $rows]);
