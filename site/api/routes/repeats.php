<?php
declare(strict_types=1);

/**
 * GET /api/repeats — REPEAT FINDINGS dashboard.
 *
 * Reads repeat_preview (api/sync/build_repeat_preview.php): one row per repeat finding on
 * each recipient's most recent active Single Audit, depth/pattern/traceability precomputed
 * through the shared lineage kernel. Full population — the old /api/repeat route's 500-
 * entity cap and per-scope cache are gone with it. Prod cannot rebuild the table; push it
 * via deploy.ps1 -PushTable repeat_preview.
 *
 *   ?action=summary   totals + depth buckets + trace split + facets (respects filters)
 *   ?action=leads     &view=findings|recipients &depth=2|3|4-6|7-9|10+ &pattern=chronic|gap|
 *                     documented|flagonly &fy &etype &state &q &sort &dir &limit &offset
 */

require_once dirname(__DIR__) . '/lib/CohortFilter.php';

$action = q_str('action') ?? 'summary';

// depth chip -> [min, max] on depth_doc
const RPT_DEPTH = ['2' => [2, 2], '3' => [3, 3], '4-6' => [4, 6], '7-9' => [7, 9], '10+' => [10, 127]];
// pattern select -> condition
const RPT_PATTERN = [
    'chronic'    => 'r.chronic = 1',
    'gap'        => 'r.gap = 1',
    'documented' => 'r.documented = 1',
    'flagonly'   => 'r.traced = 0',
];

$REGISTRY = aero_registry_ueis($pdo);

$fyS = q_str('fy');
$fy = ($fyS !== null && preg_match('/^\d{4}$/', $fyS)) ? (int) $fyS : null;
$etype = q_str('etype');
$etype = ($etype !== null && preg_match('/^[a-z-]{2,20}$/', $etype)) ? $etype : null;
$stateF = strtoupper((string) (q_str('state') ?? ''));
$q = trim((string) (q_str('q') ?? ''));
$depth = q_str('depth');
$depth = isset(RPT_DEPTH[$depth]) ? $depth : null;
$pattern = q_str('pattern');
$pattern = isset(RPT_PATTERN[$pattern]) ? $pattern : null;

$conds = ['1=1'];
$params = [];
if ($fy !== null) { $conds[] = 'r.fy = ?'; $params[] = $fy; }
if ($etype !== null) { aero_etype_cond($etype, $REGISTRY, 'r.uei', 'r.entity_type', $conds, $params); }
if (preg_match('/^[A-Z]{2}$/', $stateF)) { $conds[] = 'r.state = ?'; $params[] = $stateF; }
if (mb_strlen($q) >= 2) {
    $conds[] = '(r.label LIKE ? OR r.uei LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($depth !== null) {
    $conds[] = 'r.depth_doc BETWEEN ? AND ?';
    array_push($params, ...RPT_DEPTH[$depth]);
}
if ($pattern !== null) { $conds[] = RPT_PATTERN[$pattern]; }
$FROM = 'FROM repeat_preview r WHERE ' . implode(' AND ', $conds);

if ($action === 'summary') {
    $st = $pdo->prepare("SELECT COUNT(*) n, COUNT(DISTINCT r.uei) ents,
                SUM(r.chronic) chronic, SUM(r.gap) gaps, SUM(r.documented) documented,
                SUM(r.traced) traced, SUM(r.depth_doc >= 10) longrun, SUM(r.biennial) biennial,
                SUM(CASE WHEN r.qc_amount > 0 THEN r.qc_amount ELSE 0 END) qc_dollars,
                SUM(r.qc_amount > 0) qc_findings,
                SUM(r.depth_doc = 2) d2, SUM(r.depth_doc = 3) d3,
                SUM(r.depth_doc BETWEEN 4 AND 6) d46, SUM(r.depth_doc BETWEEN 7 AND 9) d79,
                SUM(r.depth_doc >= 10) d10 $FROM");
    $st->execute($params);
    $r = $st->fetch();

    $types = $pdo->query("SELECT DISTINCT entity_type FROM repeat_preview
                          WHERE entity_type IS NOT NULL ORDER BY entity_type")->fetchAll(PDO::FETCH_COLUMN);
    if ($REGISTRY) {
        $in = implode(',', array_fill(0, count($REGISTRY), '?'));
        $st = $pdo->prepare("SELECT 1 FROM repeat_preview WHERE uei IN ($in) LIMIT 1");
        $st->execute($REGISTRY);
        if ($st->fetchColumn()) array_unshift($types, 'stategov');
    }
    json_out([
        'mode' => 'repeats',
        'totals' => ['repeats' => (int) $r['n'], 'recipients' => (int) $r['ents'],
                     'chronic' => (int) $r['chronic'], 'gaps' => (int) $r['gaps'],
                     'documented' => (int) $r['documented'], 'traced' => (int) $r['traced'],
                     'long_running' => (int) $r['longrun'], 'biennial' => (int) $r['biennial']],
        'qc_dollars' => (float) $r['qc_dollars'],
        'qc_findings' => (int) $r['qc_findings'],
        'depth_dist' => ['2' => (int) $r['d2'], '3' => (int) $r['d3'], '4-6' => (int) $r['d46'],
                         '7-9' => (int) $r['d79'], '10+' => (int) $r['d10']],
        'fys' => array_map('intval', $pdo->query('SELECT DISTINCT fy FROM repeat_preview
                    ORDER BY fy')->fetchAll(PDO::FETCH_COLUMN)),
        'types' => $types,
        'states' => $pdo->query("SELECT DISTINCT state FROM repeat_preview
                    WHERE state > '' ORDER BY state")->fetchAll(PDO::FETCH_COLUMN),
        'generated_at' => date('c'),
    ]);
}

if ($action === 'leads') {
    $view = q_str('view') === 'recipients' ? 'recipients' : 'findings';
    $limit = q_int('limit', 100, 1, 500);
    $offset = q_int('offset', 0, 0, 1000000);
    $sortDir = q_str('dir') === 'asc' ? 'ASC' : 'DESC';

    if ($view === 'recipients') {
        $SORTS = [
            'chronic' => 'chronic', 'deepest' => 'deepest', 'repeats' => 'repeats',
            'name' => 'r.label', 'state' => "COALESCE(r.state, 'ZZ')", 'fy' => 'fy',
            'qc' => 'qc_dollars',
        ];
        $sortCol = $SORTS[q_str('sort') ?? 'chronic'] ?? 'chronic';
        // rank ties the way the persistence analysis always has: breadth at depth first,
        // then the single deepest documented chain, then raw repeat count
        $tie = 'chronic DESC, deepest DESC, repeats DESC';

        $ct = $pdo->prepare("SELECT COUNT(DISTINCT r.uei) $FROM");
        $ct->execute($params);
        $total = (int) $ct->fetchColumn();

        $st = $pdo->prepare("SELECT r.uei, MAX(r.label) label, MAX(r.state) state, MAX(r.fy) fy,
                    COUNT(*) repeats, SUM(r.chronic) chronic, SUM(r.gap) gaps,
                    SUM(r.traced) traced, MAX(r.depth_doc) deepest, MAX(r.biennial) biennial,
                    SUM(CASE WHEN r.qc_amount > 0 THEN r.qc_amount ELSE 0 END) qc_dollars
             $FROM GROUP BY r.uei ORDER BY $sortCol $sortDir, $tie LIMIT $limit OFFSET $offset");
        $st->execute($params);
        $rows = array_map(static fn ($r) => [
            'uei' => $r['uei'], 'name' => $r['label'], 'state' => $r['state'], 'fy' => (int) $r['fy'],
            'repeats' => (int) $r['repeats'], 'chronic' => (int) $r['chronic'],
            'gaps' => (int) $r['gaps'], 'traced' => (int) $r['traced'],
            'deepest' => (int) $r['deepest'], 'biennial' => (bool) $r['biennial'],
            'qc_dollars' => (float) $r['qc_dollars'],
        ], $st->fetchAll());
    } else {
        $SORTS = [
            'depth' => 'r.depth_doc', 'name' => 'r.label', 'state' => "COALESCE(r.state, 'ZZ')",
            'fy' => 'r.fy', 'qc' => 'r.qc_amount', 'req' => "COALESCE(r.req, '~')",
        ];
        $sortCol = $SORTS[q_str('sort') ?? 'depth'] ?? 'r.depth_doc';

        $ct = $pdo->prepare("SELECT COUNT(*) $FROM");
        $ct->execute($params);
        $total = (int) $ct->fetchColumn();

        $st = $pdo->prepare("SELECT r.uei, r.label, r.state, r.fy, r.report_id, r.ref, r.req,
                    r.depth_doc, r.depth_traced, r.chronic, r.gap, r.documented, r.traced,
                    r.trace_cat, r.biennial, r.mw, r.sd, r.mo, r.qcf, r.qc_amount
             $FROM ORDER BY $sortCol $sortDir, r.depth_doc DESC, r.report_id, r.ref
             LIMIT $limit OFFSET $offset");
        $st->execute($params);
        $rows = array_map(static fn ($r) => [
            'uei' => $r['uei'], 'name' => $r['label'], 'state' => $r['state'], 'fy' => (int) $r['fy'],
            'report_id' => $r['report_id'], 'ref' => $r['ref'], 'req' => $r['req'],
            'depth' => (int) $r['depth_doc'], 'depth_traced' => (int) $r['depth_traced'],
            'chronic' => (bool) $r['chronic'], 'gap' => (bool) $r['gap'],
            'documented' => (bool) $r['documented'], 'traced' => (bool) $r['traced'],
            'trace_cat' => $r['trace_cat'], 'biennial' => (bool) $r['biennial'],
            'mw' => (bool) $r['mw'], 'sd' => (bool) $r['sd'], 'mo' => (bool) $r['mo'],
            'qc' => (bool) $r['qcf'],
            'qc_amount' => $r['qc_amount'] !== null ? (float) $r['qc_amount'] : null,
        ], $st->fetchAll());
    }
    json_out(['mode' => 'leads', 'view' => $view, 'count' => count($rows), 'total' => $total,
              'offset' => $offset, 'rows' => $rows]);
}

json_out(['error' => 'unknown action', 'actions' => ['summary', 'leads']], 400);
