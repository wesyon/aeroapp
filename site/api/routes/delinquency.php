<?php
declare(strict_types=1);

/**
 * GET /api/delinquency — the METHODOLOGY PREVIEW (docs/DELINQUENCY_METHODOLOGY.md).
 *
 * Reads delinquency_preview (built by api/sync/build_delinquency_preview.php). This is NOT the
 * live Level-1 number: the Evaluation, the profile, the map, Search and Signals all still use
 * aero_l1_confirmed_by(). This route exists to show what the proposed rule WOULD conclude, beside
 * what today's rule DOES conclude, on the same data.
 *
 * SHIPPED TO PROD 2026-07-16 at the client's direction, data gaps understood (outlay re-crawl,
 * subaward grain, FAC-seeded coverage — see the caveats payload and §7 of the methodology doc).
 * The sitewide PROTOTYPE banner and each row's cited basis carry the not-a-determination framing.
 * Prod reads the locally-built delinquency_preview table (pushed via deploy.ps1 -PushTable, the
 * entity_map_point pattern) — prod cannot build it (pruned FAC, partial USAspending coverage).
 *
 *   ?action=summary            class distribution + legacy comparison + caveats
 *   ?action=leads&class=&limit= ranked by dollars at risk
 *   ?action=entity&uei=        one recipient's per-year assessment
 */

$action = q_str('action') ?? 'summary';

try {
    $pdo->query("SELECT 1 FROM delinquency_preview LIMIT 1");
} catch (\Throwable $e) {
    json_out(['error' => 'preview not built', 'hint' => 'run php api/sync/build_delinquency_preview.php'], 503);
}

/** Strongest class wins when rolling entity-years up to an entity. */
const DELINQ_RANK = ['observed' => 1, 'bracketed' => 2, 'committed' => 3, 'allocated' => 4, 'persistent' => 5, 'indeterminate' => 6, 'exempt' => 7, 'covered' => 8];

// Shared with the opinions dashboard: "State Govt" is the synthetic state_uei registry cohort,
// 'state' = other state entities minus the registry. One implementation (lib/CohortFilter.php)
// so the dashboards' dropdowns cannot drift.
require_once dirname(__DIR__) . '/lib/CohortFilter.php';
$REGISTRY_UEIS = aero_registry_ueis($pdo);
$etypeCond = function (string $etype, array &$conds, array &$params) use ($REGISTRY_UEIS): void {
    aero_etype_cond($etype, $REGISTRY_UEIS, 'd.uei', 'e.entity_type', $conds, $params);
};

$caveats = [
    'This is a preview. It does not drive the Evaluation, the map, Search or Signals.',
    'Outlays: ~800k awards still hold pre-fix values (per-fiscal-year error reached 59%).',
    'Pass-through: subaward_edge is YEAR-grain, but 73.7% of entities have a fiscal year spanning two calendar years.',
    'Coverage: the USAspending crawl is FAC-seeded, so entities that never filed cannot appear at all.',
    'Loans: only 704 of 12,759 loan awards carry a value; "continuing compliance" is not exposed by USAspending.',
];

if ($action === 'summary') {
    // Page-level filters: the same fy/etype the leads take, so the KPIs, the distribution bar
    // and the leads all describe ONE filtered population. Facets below stay unfiltered — they
    // populate the dropdowns. fy parsed as a string (q_int clamps its default into [min,max]).
    $fyS = q_str('fy');
    $fy = ($fyS !== null && preg_match('/^\d{4}$/', $fyS)) ? (int) $fyS : null;
    $etype = q_str('etype');
    $etype = ($etype !== null && preg_match('/^[a-z-]{2,20}$/', $etype)) ? $etype : null;
    $stateF = strtoupper((string) (q_str('state') ?? ''));
    $conds = [];
    $params = [];
    if ($fy !== null) { $conds[] = 'd.fy = ?'; $params[] = $fy; }
    if ($etype !== null) { $etypeCond($etype, $conds, $params); }
    if (preg_match('/^[A-Z]{2}$/', $stateF)) { $conds[] = 'e.state = ?'; $params[] = $stateF; }
    $q = trim((string) (q_str('q') ?? ''));
    if (mb_strlen($q) >= 2) {
        $conds[] = '(e.display_name LIKE ? OR d.uei LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    $W = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
    $FROM = "FROM delinquency_preview d LEFT JOIN entity e ON e.uei = d.uei $W";
    $q = function (string $sql) use ($pdo, $params) { $st = $pdo->prepare($sql); $st->execute($params); return $st; };

    // per entity-year
    $byYear = [];
    foreach ($q("SELECT d.class, COUNT(*) n, SUM(d.exposure) exp $FROM GROUP BY d.class") as $r) {
        $byYear[$r['class']] = ['entity_years' => (int) $r['n'], 'exposure' => (float) $r['exp']];
    }
    // per entity — an entity takes its STRONGEST year (within the filtered population)
    $best = [];
    foreach ($q("SELECT d.uei, d.class, d.exposure $FROM") as $r) {
        $rank = DELINQ_RANK[$r['class']] ?? 9;
        if (!isset($best[$r['uei']]) || $rank < $best[$r['uei']]['rank']) {
            $best[$r['uei']] = ['rank' => $rank, 'class' => $r['class'], 'exposure' => (float) $r['exposure']];
        }
    }
    $byEntity = [];
    foreach ($best as $b) {
        $byEntity[$b['class']]['entities'] = ($byEntity[$b['class']]['entities'] ?? 0) + 1;
        $byEntity[$b['class']]['exposure'] = ($byEntity[$b['class']]['exposure'] ?? 0) + $b['exposure'];
    }
    // what TODAY's rule concludes, for the same (filtered) population
    $legacyYears = (int) $q("SELECT COUNT(*) $FROM " . ($W ? 'AND' : 'WHERE') . " d.legacy_missing = 1")->fetchColumn();
    $legacyEnt = (int) $q("SELECT COUNT(DISTINCT d.uei) $FROM " . ($W ? 'AND' : 'WHERE') . " d.legacy_missing = 1")->fetchColumn();
    // the money we refuse to use
    $quar = (int) $q("SELECT COUNT(DISTINCT d.uei) $FROM " . ($W ? 'AND' : 'WHERE') . " d.money_trust <> 'ok'")->fetchColumn();
    $uncov = (int) $q("SELECT COUNT(DISTINCT d.uei) $FROM " . ($W ? 'AND' : 'WHERE') . " d.covered = 0")->fetchColumn();

    $defensible = ($byEntity['observed']['entities'] ?? 0) + ($byEntity['bracketed']['entities'] ?? 0);
    json_out([
        'mode' => 'preview',
        'by_entity' => $byEntity,
        'by_entity_year' => $byYear,
        'legacy' => ['entities' => $legacyEnt, 'entity_years' => $legacyYears,
                     'label' => "today's rule (aero_l1_confirmed_by) calls these delinquent"],
        'headline' => [
            'defensible_entities' => $defensible,
            'probable_entities'   => $byEntity['persistent']['entities'] ?? 0,
            'unknown_entities'    => $byEntity['indeterminate']['entities'] ?? 0,
            'exempt_entities'     => $byEntity['exempt']['entities'] ?? 0,
        ],
        'evidence_gaps' => ['quarantined_money' => $quar, 'never_crawled' => $uncov],
        // facets for the filters — type VALUES; the frontend labels/orders them via TYPE_OPTS so
        // the dropdown reads identically to Evaluation/Search ('stategov' first when present)
        'fys'   => array_map('intval', $pdo->query("SELECT DISTINCT fy FROM delinquency_preview ORDER BY fy")->fetchAll(PDO::FETCH_COLUMN)),
        'types' => (function () use ($pdo, $REGISTRY_UEIS): array {
            $t = $pdo->query("SELECT DISTINCT e.entity_type FROM delinquency_preview d
                              JOIN entity e ON e.uei = d.uei
                              WHERE e.entity_type IS NOT NULL ORDER BY e.entity_type")->fetchAll(PDO::FETCH_COLUMN);
            if ($REGISTRY_UEIS) {
                $in = implode(',', array_fill(0, count($REGISTRY_UEIS), '?'));
                $st = $pdo->prepare("SELECT 1 FROM delinquency_preview WHERE uei IN ($in) LIMIT 1");
                $st->execute($REGISTRY_UEIS);
                if ($st->fetchColumn()) array_unshift($t, 'stategov');
            }
            return $t;
        })(),
        'states' => $pdo->query("SELECT DISTINCT e.state FROM delinquency_preview d
                                 JOIN entity e ON e.uei = d.uei
                                 WHERE e.state IS NOT NULL AND e.state <> '' ORDER BY e.state")->fetchAll(PDO::FETCH_COLUMN),
        'caveats' => $caveats,
        'generated_at' => date('c'),
    ]);
}

if ($action === 'leads') {
    $class = q_str('class');
    $limit = q_int('limit', 50, 1, 500);
    // q_int clamps its DEFAULT into [min,max] too, so an absent fy would become min and match
    // nothing — parse it as a string instead: absent/non-year = no year filter.
    $fyS   = q_str('fy');
    $fy    = ($fyS !== null && preg_match('/^\d{4}$/', $fyS)) ? (int) $fyS : null;
    $etype = q_str('etype');                          // entity type value ('stategov' = registry cohort)
    $stateF = strtoupper((string) (q_str('state') ?? ''));
    $conds = [];
    $params = [];
    if ($class !== null && isset(DELINQ_RANK[$class])) { $conds[] = 'd.class = ?'; $params[] = $class; }
    if ($fy !== null) { $conds[] = 'd.fy = ?'; $params[] = $fy; }
    if ($etype !== null && $etype !== '' && preg_match('/^[a-z-]{2,20}$/', $etype)) {
        $etypeCond($etype, $conds, $params);
    }
    if (preg_match('/^[A-Z]{2}$/', $stateF)) { $conds[] = 'e.state = ?'; $params[] = $stateF; }
    $q = trim((string) (q_str('q') ?? ''));
    if (mb_strlen($q) >= 2) {
        $conds[] = '(e.display_name LIKE ? OR d.uei LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    $offset = q_int('offset', 0, 0, 1000000);
    // column sort — whitelisted; class sorts by grade rank, not alphabetically
    // COALESCE: prod's entity table is HHS-pruned, so display_name/state are NULL for some
    // preview UEIs — without it a name sort clumps blank rows first (MySQL NULLs-first ASC).
    $SORTS = [
        'exposure' => 'd.exposure', 'name' => 'COALESCE(e.display_name, d.uei)',
        'state' => "COALESCE(e.state, 'ZZ')", 'fy' => 'd.fy',
        'outlays' => 'd.outlays', 'oblig' => 'd.pop_oblig',
        'class' => "FIELD(d.class,'observed','bracketed','committed','allocated','persistent','indeterminate','exempt','covered')",
    ];
    $sortCol = $SORTS[q_str('sort') ?? 'exposure'] ?? 'd.exposure';
    $sortDir = q_str('dir') === 'asc' ? 'ASC' : 'DESC';
    $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
    // total for the filter, so the UI can page ("showing X of Y · load more")
    $ct = $pdo->prepare("SELECT COUNT(*) FROM delinquency_preview d LEFT JOIN entity e ON e.uei = d.uei $where");
    $ct->execute($params);
    $total = (int) $ct->fetchColumn();
    $st = $pdo->prepare(
        "SELECT d.uei, d.fy, d.class, d.fy_end, d.trigger_amt, d.estimate, d.outlays,
                d.subawards, d.loans, d.pop_oblig, d.prior_sefa, d.exposure, d.money_trust, d.covered,
                d.legacy_missing, d.basis, e.display_name name, e.state
         FROM delinquency_preview d LEFT JOIN entity e ON e.uei = d.uei
         $where ORDER BY $sortCol $sortDir, d.exposure DESC LIMIT $limit OFFSET $offset");
    $st->execute($params);
    $rows = array_map(static fn ($r) => [
        'uei' => $r['uei'], 'name' => $r['name'], 'state' => $r['state'],
        'fy' => (int) $r['fy'], 'fy_end' => $r['fy_end'], 'class' => $r['class'],
        'trigger' => (float) $r['trigger_amt'], 'estimate' => (float) $r['estimate'],
        'evidence' => ['outlays' => (float) $r['outlays'], 'subawards' => (float) $r['subawards'],
                       'loans' => (float) $r['loans'], 'pop_obligations' => (float) $r['pop_oblig'],
                       'prior_sefa' => (float) $r['prior_sefa']],
        'exposure' => (float) $r['exposure'],
        'money_trust' => $r['money_trust'], 'covered' => (int) $r['covered'] === 1,
        'legacy_missing' => (int) $r['legacy_missing'] === 1,
        'basis' => $r['basis'],
    ], $st->fetchAll());
    json_out(['mode' => 'leads', 'class' => $class, 'count' => count($rows), 'total' => $total,
              'offset' => $offset, 'rows' => $rows, 'caveats' => $caveats]);
}

if ($action === 'entity') {
    $uei = q_str('uei');
    if ($uei === null || !preg_match('/^[A-Za-z0-9]{12}$/', $uei)) json_out(['error' => 'valid uei required'], 400);
    $st = $pdo->prepare("SELECT * FROM delinquency_preview WHERE uei = ? ORDER BY fy");
    $st->execute([$uei]);
    json_out(['mode' => 'entity', 'uei' => $uei, 'years' => $st->fetchAll(PDO::FETCH_ASSOC), 'caveats' => $caveats]);
}

json_out(['error' => 'unknown action', 'actions' => ['summary', 'leads', 'entity']], 400);
