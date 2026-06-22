<?php
declare(strict_types=1);
/**
 * GET /api/subaward?uei=XXXXXXXXXXXX — subaward (FSRS assistance) flow for one entity.
 * Reads the pre-aggregated subaward_edge table (built locally from the ~2.8 GB detail
 * table, pushed to prod) and returns both directions:
 *   sent     — rows where this entity is the prime (funds passed DOWN to subrecipients)
 *   received — rows where this entity is the sub  (funds received as a subrecipient)
 * Each counterparty is enriched from aero_score so the UI can link through to its
 * profile and show its AERO tier when it is in our hub.
 */

$uei = q_str('uei');
if ($uei === null || !preg_match('/^[A-Za-z0-9]{12}$/', $uei)) {
    json_out(['error' => 'a valid 12-char uei is required'], 400);
}

// subaward_edge may not exist yet (first deploy before the table is pushed) — degrade
// to an empty, well-formed payload rather than 500.
if (!$pdo->query("SHOW TABLES LIKE 'subaward_edge'")->fetchColumn()) {
    json_out(['uei' => $uei, 'available' => false, 'years' => [],
              'sent' => ['rows' => [], 'total' => 0, 'subawards' => 0, 'partners' => 0],
              'received' => ['rows' => [], 'total' => 0, 'subawards' => 0, 'partners' => 0]]);
}

// Multi-UEI governments: expand to the whole crosswalk group so a state's subawards
// under former UEIs are included (mirrors grantee.php's group handling).
$ueiSet = [$uei];
$grpStmt = $pdo->prepare("SELECT ueis FROM state_uei WHERE ueis LIKE ?");
$grpStmt->execute(['%' . $uei . '%']);
if (($grpUeis = $grpStmt->fetchColumn()) !== false && $grpUeis !== null) {
    $set = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $grpUeis) ?: [])));
    if (count($set) > 1 && in_array($uei, $set, true)) $ueiSet = $set;
}
$IN = implode(',', array_fill(0, count($ueiSet), '?'));

const SUBAWARD_ROW_LIMIT = 500;   // default rows per direction; totals are computed over all
// "?all=1" lifts the default cap (the UI's "Show all" button). Still bounded so a
// pathological prime can't return an unbounded payload; real max is ~1,200 partners.
$rowLimit = q_str('all') === '1' ? 100000 : SUBAWARD_ROW_LIMIT;

// Optional year filter (the Passthrough "Filters" section). When set, totals + rows are
// scoped to that subaward year; otherwise all years are aggregated.
$yr = q_str('year');
$year = ($yr !== null && preg_match('/^\d{4}$/', $yr)) ? (int) $yr : null;

// Counterparty entity-type lookup (Government / Higher Ed / Nonprofit / For-Profit), derived
// from FSRS sub_business_types — may not exist yet on a fresh deploy, so join conditionally.
$hasType = (bool) $pdo->query("SHOW TABLES LIKE 'subaward_entity_type'")->fetchColumn();
// aero_score entity_type -> [display label, owes a Single Audit? (2 CFR 200.501)]
$aeroEtype = fn (?string $t): array => match ($t) {
    'state', 'local', 'tribal' => ['Government', 1],
    'higher-ed'                => ['Higher Ed', 1],
    'non-profit'               => ['Nonprofit', 1],
    'for-profit'               => ['For-Profit', 0],
    default                    => [null, 0],
};

/**
 * One direction of the flow. $selfCol is the column matching this entity (the side we
 * filter on); $otherCol is the counterparty we group/list. Returns [rows, totals].
 */
$direction = function (string $selfCol, string $otherCol) use ($pdo, $ueiSet, $IN, $rowLimit, $year, $hasType, $aeroEtype): array {
    $yc  = $year !== null ? ' AND year = ?'   : '';   // plain-table (totals)
    $yce = $year !== null ? ' AND e.year = ?' : '';   // aliased (rows)
    $params = $year !== null ? array_merge($ueiSet, [$year]) : $ueiSet;
    $btJoin = $hasType ? "LEFT JOIN subaward_entity_type bt ON bt.uei = e.$otherCol" : '';
    $btSel  = $hasType ? 'MAX(bt.entity_type) bt_type, MAX(bt.audit_applicable) bt_audit,' : 'NULL bt_type, NULL bt_audit,';

    // totals over the FULL set (rows are capped for payload size)
    $tot = $pdo->prepare(
        "SELECT COUNT(DISTINCT $otherCol) partners, COALESCE(SUM(subawards),0) subawards,
                COALESCE(SUM(total_amount),0) total
         FROM subaward_edge WHERE $selfCol IN ($IN)$yc"
    );
    $tot->execute($params);
    $totals = $tot->fetch(PDO::FETCH_ASSOC) ?: ['partners' => 0, 'subawards' => 0, 'total' => 0];

    // top counterparties by total $; aggregate across the group's UEIs (and the filtered
    // years) and LEFT JOIN aero_score for the audit/location enrichment + profile link.
    $rowsStmt = $pdo->prepare(
        "SELECT e.$otherCol uei,
                COALESCE(MAX(a.display_name), MAX(e." . ($otherCol === 'sub_vendor_uei' ? 'sub_name' : 'prime_name') . ")) name,
                SUM(e.subawards) subawards, SUM(e.total_amount) total, MAX(e.max_amount) max_award,
                LEFT(GROUP_CONCAT(e.alns SEPARATOR ','), 1000) alns,
                MAX(a.state) state, MAX(a.entity_type) aero_type, $btSel
                MAX(a.audit_count) audit_count, MAX(a.latest_audit_year) latest_year,
                MAX(a.is_hhs) is_hhs, MAX(a.has_fac) has_fac, MAX(a.latest_audit_year IS NOT NULL) in_hub
         FROM subaward_edge e
         LEFT JOIN entity a ON a.uei = e.$otherCol   -- the recipient directory (identity + in_hub); was aero_score
         $btJoin
         WHERE e.$selfCol IN ($IN)$yce
         GROUP BY e.$otherCol
         ORDER BY total DESC
         LIMIT " . (int) $rowLimit
    );
    $rowsStmt->execute($params);
    $rows = array_map(function ($r) use ($aeroEtype) {
        // entity type + Single-Audit applicability: prefer the FSRS business-type
        // classification (covers non-hub subs); fall back to aero_score (in-hub recipients).
        [$aLabel, $aAudit] = $aeroEtype($r['aero_type']);
        $btType = $r['bt_type'] ?? null;
        $etype  = $btType ?: $aLabel;
        $audit  = $btType !== null ? (int) $r['bt_audit'] : $aAudit;
        return [
            'uei'         => $r['uei'],
            'name'        => $r['name'],
            'subawards'   => (int) $r['subawards'],
            'total'       => $r['total'] !== null ? (float) $r['total'] : null,
            'max_award'   => $r['max_award'] !== null ? (float) $r['max_award'] : null,
            'alns'        => $r['alns'],
            'state'       => $r['state'],
            'entity_type' => $etype,
            'audit_applicable' => (bool) $audit,
            'audit_count' => $r['audit_count'] !== null ? (int) $r['audit_count'] : null,
            'latest_year' => $r['latest_year'] !== null ? (int) $r['latest_year'] : null,
            'is_hhs'      => (int) $r['is_hhs'] === 1,
            'in_hub'      => (int) $r['in_hub'] === 1,
            // filed a Single Audit somewhere in FAC (has_fac survives the prod HHS-prune),
            // so a sub audited under a non-HHS agency reads "audited" even though prod no
            // longer holds the report itself.
            'audit_filer' => (int) ($r['has_fac'] ?? 0) === 1,
        ];
    }, $rowsStmt->fetchAll());

    return [
        'rows'      => $rows,
        'partners'  => (int) $totals['partners'],
        'subawards' => (int) $totals['subawards'],
        'total'     => (float) $totals['total'],
        'capped'    => (int) $totals['partners'] > $rowLimit,
    ];
};

$sent     = $direction('prime_entity_uei', 'sub_vendor_uei');
$received = $direction('sub_vendor_uei', 'prime_entity_uei');

// Years available for this entity (either direction) — drives the filter dropdown. Always
// the FULL list regardless of the active year filter, so the dropdown never collapses.
$yStmt = $pdo->prepare(
    "SELECT DISTINCT year FROM (
        SELECT year FROM subaward_edge WHERE prime_entity_uei IN ($IN)
        UNION ALL SELECT year FROM subaward_edge WHERE sub_vendor_uei IN ($IN)
     ) y ORDER BY year DESC"
);
$yStmt->execute(array_merge($ueiSet, $ueiSet));
$years = array_map('intval', $yStmt->fetchAll(PDO::FETCH_COLUMN));

// Enrich ALN codes -> program titles in one catalog lookup for all rows (both directions),
// attaching a programs:[{aln,title}] list to each row. The edge table stores ALNs as a
// "93.575, 93.596" string; split, dedupe, look up titles from the assistance-listing catalog.
$alnSet = [];
foreach ([$sent['rows'], $received['rows']] as $rs) {
    foreach ($rs as $r) {
        foreach (preg_split('/[,\s]+/', (string) $r['alns']) as $a) {
            $a = trim($a);
            if ($a !== '') $alnSet[$a] = true;
        }
    }
}
$alnName = [];
if ($alnSet) {
    $in = implode(',', array_fill(0, count($alnSet), '?'));
    $st = $pdo->prepare("SELECT assistance_listing_id, title FROM assistance_listing WHERE assistance_listing_id IN ($in)");
    $st->execute(array_keys($alnSet));
    foreach ($st as $r) $alnName[$r['assistance_listing_id']] = $r['title'];
}
$attachPrograms = function (array &$rows) use ($alnName) {
    foreach ($rows as &$r) {
        $progs = [];
        $seen = [];                                  // dedupe ALNs repeated across year-rows
        foreach (preg_split('/[,\s]+/', (string) $r['alns']) as $a) {
            $a = trim($a);
            if ($a === '' || isset($seen[$a])) continue;
            $seen[$a] = true;
            $progs[] = ['aln' => $a, 'title' => $alnName[$a] ?? null];
        }
        $r['programs'] = $progs;
    }
    unset($r);
};
$attachPrograms($sent['rows']);
$attachPrograms($received['rows']);

// "Covered" status: a counterparty with no Single Audit under its OWN UEI may still be
// audited as a COMPONENT UNIT — listed as an additional UEI on a parent entity's active
// audit (e.g. a state board of education covered by the statewide single audit). One
// batched lookup over fac_additional_ueis; clears the >=$1M-no-audit flag for these.
$cpUeis = [];
foreach ([$sent['rows'], $received['rows']] as $rs) {
    foreach ($rs as $r) $cpUeis[$r['uei']] = true;
}
$coveredBy = [];
if ($cpUeis) {
    $in = implode(',', array_fill(0, count($cpUeis), '?'));
    $st = $pdo->prepare(
        "SELECT au.additional_uei uei, g.auditee_name nm, g.audit_year yr
         FROM fac_additional_ueis au JOIN fac_general g ON g.report_id = au.report_id
         WHERE au.additional_uei IN ($in) AND g.is_active = 1
         ORDER BY g.audit_year DESC"
    );
    $st->execute(array_keys($cpUeis));
    foreach ($st as $r) {
        if (!isset($coveredBy[$r['uei']])) {            // first = most recent (DESC)
            $coveredBy[$r['uei']] = ['name' => $r['nm'], 'year' => (int) $r['yr']];
        }
    }
}
$attachCovered = function (array &$rows) use ($coveredBy) {
    foreach ($rows as &$r) {
        $c = $coveredBy[$r['uei']] ?? null;
        $r['covered_by']   = $c['name'] ?? null;
        $r['covered_year'] = $c['year'] ?? null;
    }
    unset($r);
};
$attachCovered($sent['rows']);
$attachCovered($received['rows']);

json_out(['uei' => $uei, 'available' => true, 'years' => $years, 'year' => $year, 'sent' => $sent, 'received' => $received]);
