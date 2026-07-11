<?php
declare(strict_types=1);
/**
 * GET /api/recipients — searchable recipient list (Search Recipients page).
 * Filters: q (name contains / UEI prefix), state, tier, type (entity_type), hhs=1.
 * Single-table read off aero_score's denormalized columns; ordered by name.
 */

// Crosswalk (state_uei) governments → the synthetic "State Govt" and "US Territories"
// facets, mirroring the Evaluation's stategov/territory split. Map each UEI to the
// crosswalk's canonical label so the list shows one consistent name per government
// (source FAC names vary in casing and suffixes). Territories are split out by state_code.
const TERRITORIES = ['AS', 'GU', 'MP', 'PR', 'VI'];
$stateGovt = [];                                    // UEI(upper) => crosswalk label ('' if none) — ALL registry govts
$terrSet   = [];                                    // UEI(upper) => true for territory governments
$sgGroups  = [];                                    // multi-UEI governments: each group's member set
foreach ($pdo->query("SELECT state_code, label, ueis FROM state_uei") as $r) {
    $isTerr = in_array($r['state_code'], TERRITORIES, true);
    $set = [];
    foreach (preg_split('/[\s,]+/', (string) $r['ueis']) as $u) {
        $u = strtoupper(trim($u));
        if ($u !== '') { $stateGovt[$u] = (string) ($r['label'] ?? ''); $set[] = $u; if ($isTerr) $terrSet[$u] = true; }
    }
    if (count($set) > 1) $sgGroups[] = $set;
}
$allRegList = array_keys($stateGovt);                       // all crosswalk govts (states + DC + territories)
$terrList   = array_keys($terrSet);                         // territory govts only
$sgList     = array_keys(array_diff_key($stateGovt, $terrSet)); // state govts (50 states + DC), territories excluded

// Multi-UEI governments (UEI successions): the entity directory holds a row per member UEI,
// but a government should appear ONCE — under its canonical (current) UEI, with its audit
// history MERGED across the succession (mirrors how compute_scores aliased the group to one
// scored row). Pick canonical = member with the latest active audit year (tie-break federal $),
// exclude the others from the universe, and precompute the merged audit_count / latest_year /
// federal_latest to override on the canonical row.
$former = [];                       // non-canonical member UEIs — excluded from the listing
$mergedById = [];                   // canonical uei => [audit_count, latest_year, federal]
$grpMembers = [];
foreach ($sgGroups as $set) foreach ($set as $u) $grpMembers[$u] = true;
if ($grpMembers) {
    $gin = implode(',', array_fill(0, count($grpMembers), '?'));
    $me = [];                       // member uei => [latest_audit_year, federal_latest] (from the directory)
    $st = $pdo->prepare("SELECT uei, latest_audit_year ly, federal_latest fl FROM entity WHERE uei IN ($gin)");
    $st->execute(array_keys($grpMembers));
    foreach ($st as $r) $me[$r['uei']] = $r;
    $my = [];                       // member uei => set of active audit years (for the merged distinct count)
    $st = $pdo->prepare("SELECT auditee_uei u, audit_year y FROM fac_general WHERE auditee_uei IN ($gin) AND is_active = 1");
    $st->execute(array_keys($grpMembers));
    foreach ($st as $r) $my[$r['u']][(int) $r['y']] = true;
    foreach ($sgGroups as $set) {
        $canon = null; $best = [-1, -1.0];
        foreach ($set as $u) {
            $cand = [(int) ($me[$u]['ly'] ?? -1), (float) ($me[$u]['fl'] ?? -1)];
            if ($cand > $best) { $best = $cand; $canon = $u; }
        }
        if ($canon === null) continue;
        $years = [];
        foreach ($set as $u) { $years += $my[$u] ?? []; if ($u !== $canon) $former[$u] = true; }
        $mergedById[$canon] = [
            'audit_count' => count($years),
            'latest_year' => $years ? max(array_keys($years)) : (int) ($me[$canon]['ly'] ?? 0),
            'federal'     => $me[$canon]['fl'] ?? null,
        ];
    }
}

// Universe = entities with an active FAC presence (the authoritative recipient set, maintained
// independent of the score), minus non-canonical multi-UEI members. aero_score is LEFT-JOINed
// (alias s) purely for the score/tier — a missing/stale score never removes a recipient.
$where = ['e.latest_audit_year IS NOT NULL'];
$params = [];
if ($former) {
    $where[] = 'e.uei NOT IN (' . implode(',', array_fill(0, count($former), '?')) . ')';
    array_push($params, ...array_keys($former));
}

if (($q = q_str('q')) !== null) {
    $cond = '(e.display_name LIKE ? OR e.uei LIKE ?';
    $params[] = '%' . $q . '%';
    $params[] = $q . '%';                       // UEI prefix
    // A former UEI of a multi-UEI government has no aero_score row of its own (the
    // government is scored once, under its current UEI) — when the query prefixes any
    // group member, match the whole group so the consolidated entity is still found.
    $qU = strtoupper($q);
    $grpUeis = [];
    foreach ($sgGroups as $set) {
        foreach ($set as $u) {
            if (str_starts_with($u, $qU)) { array_push($grpUeis, ...$set); break; }
        }
    }
    if ($grpUeis) {
        $grpUeis = array_values(array_unique($grpUeis));
        $cond .= ' OR e.uei IN (' . implode(',', array_fill(0, count($grpUeis), '?')) . ')';
        array_push($params, ...$grpUeis);
    }
    // A purely numeric query (EIN, dashes/spaces optional) is an EIN lookup: EINs live on
    // the FAC filings (auditee_ein), not aero_score, so match by prefix via a subquery
    // correlated on the indexed auditee_uei — the same shape as the agency EXISTS below.
    if (preg_match('/^[\d\- ]+$/', $q)) {
        $ein = preg_replace('/\D+/', '', $q);
        if ($ein !== '') {
            $cond .= ' OR EXISTS (SELECT 1 FROM fac_general fg
                                  WHERE fg.auditee_uei = e.uei AND fg.auditee_ein LIKE ?)';
            $params[] = $ein . '%';
        }
    }
    // A UEI-shaped query may be an ADDITIONAL UEI: an auditee can register several UEIs
    // but files (and is scored) under one primary auditee_uei. Resolve any additional UEI
    // back to that primary so e.g. an Alabama subaward UEI finds "State of Alabama".
    if (preg_match('/^[A-Za-z0-9]{2,12}$/', $q)) {
        $cond .= ' OR e.uei IN (SELECT auditee_uei FROM fac_additional_ueis WHERE additional_uei LIKE ?)';
        $params[] = $q . '%';
    }
    $where[] = $cond . ')';
}
if (($state = q_str('state')) !== null) { $where[] = 'e.state = ?'; $params[] = $state; }
if (($tier = q_str('tier')) !== null
    && in_array($tier, ['Clean', 'Minimal', 'Moderate', 'Elevated', 'Substantial', 'Severe'], true)) {
    $where[] = 's.tier = ?';   // score-only: a recipient without a (fresh) score won't match a tier filter
    $params[] = $tier;
}
if (($type = q_str('type')) !== null) {
    if ($type === 'State Govt') {                       // crosswalked state governments (50 states + DC)
        if ($sgList) { $where[] = 'e.uei IN (' . implode(',', array_fill(0, count($sgList), '?')) . ')'; array_push($params, ...$sgList); }
        else { $where[] = '1=0'; }
    } elseif ($type === 'US Territories') {             // crosswalked territory governments
        if ($terrList) { $where[] = 'e.uei IN (' . implode(',', array_fill(0, count($terrList), '?')) . ')'; array_push($params, ...$terrList); }
        else { $where[] = '1=0'; }
    } elseif ($type === 'state') {                      // generic "state" entities, minus every crosswalk govt
        $where[] = 'e.entity_type = ?';
        $params[] = 'state';
        if ($allRegList) { $where[] = 'e.uei NOT IN (' . implode(',', array_fill(0, count($allRegList), '?')) . ')'; array_push($params, ...$allRegList); }
    } else {
        $where[] = 'e.entity_type = ?';
        $params[] = $type;
    }
}
if (q_str('hhs') === '1') { $where[] = 'e.is_hhs = 1'; }

// Filter to recipients with at least one award from a given federal agency (ALN
// prefix). HHS (93) uses the precomputed is_hhs flag; other agencies use an EXISTS
// against the awards table (idx_awards_uei_agency keeps the per-recipient lookup cheap).
if (($agency = q_str('agency')) !== null && preg_match('/^[0-9A-Za-z]{2,4}$/', $agency)) {
    if ($agency === '93') {
        $where[] = 'e.is_hhs = 1';
    } else {
        $where[] = 'EXISTS (SELECT 1 FROM fac_federal_awards fa
                            WHERE fa.auditee_uei = e.uei AND fa.federal_agency_prefix = ?)';
        $params[] = $agency;
    }
}

$whereSql = 'WHERE ' . implode(' AND ', $where);   // always carries the universe base condition
$limit = q_int('limit', 200, 1, 1000);
$offset = q_int('offset', 0, 0, 5000000);   // paginated scroll (e.g. the sidebar recipient browser)

// Universe + identity from the entity directory (authoritative, score-independent);
// aero_score (alias s) LEFT-joined only for the composite/tier display.
$from = 'FROM entity e LEFT JOIN aero_score s ON s.uei = e.uei';
$countStmt = $pdo->prepare("SELECT COUNT(*) $from $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$rowsStmt = $pdo->prepare(
    "SELECT e.uei, e.display_name name, e.state, e.entity_type, e.audit_count, e.latest_audit_year,
            e.federal_latest, s.composite_score, s.tier
     $from $whereSql
     ORDER BY e.display_name, e.uei LIMIT $limit OFFSET $offset"
);
$rowsStmt->execute($params);
$rows = array_map(function ($r) use ($stateGovt, $terrSet, $mergedById) {
    $uup = strtoupper((string) $r['uei']);
    $mg  = $mergedById[$r['uei']] ?? null;   // canonical multi-UEI government: merged audit history across the succession
    $fed = $mg['federal'] ?? $r['federal_latest'];
    return [
        'uei'            => $r['uei'],
        'name'           => (isset($stateGovt[$uup]) && $stateGovt[$uup] !== '') ? $stateGovt[$uup] : $r['name'],
        'state'          => $r['state'],
        'entity_type'    => isset($terrSet[$uup]) ? 'US Territories'
                              : (isset($stateGovt[$uup]) ? 'State Govt' : $r['entity_type']),
        'audits'         => (int) ($mg['audit_count'] ?? $r['audit_count']),
        'latest_year'    => (int) ($mg['latest_year'] ?? $r['latest_audit_year']),
        'federal_latest' => $fed !== null ? (float) $fed : null,
        'composite'      => $r['composite_score'] !== null ? (float) $r['composite_score'] : null,
        'tier'           => $r['tier'],
    ];
}, $rowsStmt->fetchAll());

// enrich the shown page (≤ limit rows) with EIN, SAM registration, and the next audit
// due date — two batched lookups, cheap on the indexed uei columns
if ($rows) {
    $ueis = array_column($rows, 'uei');
    $in = implode(',', array_fill(0, count($ueis), '?'));

    // EIN + latest fiscal-year end from the active FAC reports. The next Single Audit
    // is due 9 months after the NEXT fiscal year ends (2 CFR 200.512). fyp carries the
    // latest filing's audit period (CONCAT sorts by the uniform Y-m-d date first), so
    // biennial filers (2 CFR 200.504) get the two-year stride below.
    $fac = [];
    $st = $pdo->prepare("SELECT auditee_uei u, MAX(auditee_ein) ein, MAX(fy_end_date) fye,
                                MAX(CONCAT(fy_end_date, '|', COALESCE(audit_period_covered, ''))) fyp
                         FROM fac_general WHERE auditee_uei IN ($in) AND is_active = 1 GROUP BY auditee_uei");
    $st->execute($ueis);
    foreach ($st as $r) $fac[$r['u']] = $r;

    // SAM registration: resolve across the whole crosswalk GROUP, not just the displayed
    // UEI. A multi-UEI state government often holds its active registration under a
    // FORMER UEI (the row shows the current one), so checking only that UEI made
    // genuinely-registered states read blank. Mirrors the grantee profile's group-aware
    // lookup; prefer an Active registration, then the most recent.
    $groupOf = [];                                   // uei(upper) => sibling member set
    foreach ($sgGroups as $set) foreach ($set as $u) $groupOf[$u] = $set;
    $candOf = []; $allCand = [];                      // displayed uei => candidate ueis
    foreach ($ueis as $u) {
        $cands = $groupOf[strtoupper((string) $u)] ?? [$u];
        $candOf[$u] = $cands;
        foreach ($cands as $c) $allCand[$c] = true;
    }
    $samRow = [];
    if ($allCand) {
        $cin = implode(',', array_fill(0, count($allCand), '?'));
        $st = $pdo->prepare("SELECT uei, registration_status, registration_expiration_date, registration_date
                             FROM sam_entity WHERE uei IN ($cin)");
        $st->execute(array_keys($allCand));
        foreach ($st as $r) $samRow[$r['uei']] = $r;
    }
    // best registration among a group's members: Active wins, then any real (even
    // lapsed) registration over the API-enriched placeholders ('ID Assigned' = UEI
    // issued but never registered, 'Not Found' = unknown to SAM's public API),
    // then the latest registration_date
    $samRank = fn (?string $st) => $st === 'Active' ? 3 : ($st === 'Not Found' ? 0 : ($st === 'ID Assigned' ? 1 : 2));
    $bestSam = function (array $cands) use ($samRow, $samRank) {
        $best = null;
        foreach ($cands as $u) {
            $r = $samRow[$u] ?? null;
            if ($r === null) continue;
            if ($best === null) { $best = $r; continue; }
            $rk = $samRank($r['registration_status']);
            $bk = $samRank($best['registration_status']);
            if ($rk !== $bk) { if ($rk > $bk) $best = $r; continue; }
            if ((string) ($r['registration_date'] ?? '') > (string) ($best['registration_date'] ?? '')) $best = $r;
        }
        return $best;
    };

    // USAspending money for the shown page: sum obligations + outlays across each row's
    // FULL rollup family — crosswalk group siblings, auditor-declared additional UEIs,
    // and curated components — so state governments read their real totals (a parent-only
    // sum would repeat the Oklahoma "-$47M vs $14.9B" artifact the linkage work fixed).
    $famOf = [];   // display uei => member uei set (incl. itself / group siblings)
    $allFam = [];
    foreach ($ueis as $u) {
        $fam = array_values(array_unique($candOf[$u] ?? [$u]));
        $famOf[$u] = $fam;
        foreach ($fam as $m) $allFam[$m] = true;
    }
    if ($allFam) {
        $fin = implode(',', array_fill(0, count($allFam), '?'));
        $ownerOf = [];   // any family member => display uei (first claim wins)
        foreach ($famOf as $u => $fam) foreach ($fam as $m) $ownerOf[$m] ??= $u;
        $mq = $pdo->prepare(
            "SELECT auditee_uei p, additional_uei m FROM fac_additional_ueis WHERE auditee_uei IN ($fin)
             UNION
             SELECT uei p, related_uei m FROM entity_related_uei WHERE uei IN ($fin)"
        );
        $mq->execute(array_merge(array_keys($allFam), array_keys($allFam)));
        foreach ($mq as $r) {
            $owner = $ownerOf[$r['p']] ?? null;
            if ($owner !== null && $r['m'] !== '' && !isset($ownerOf[$r['m']])) {
                $ownerOf[$r['m']] = $owner;
                $famOf[$owner][] = $r['m'];
            }
        }
        $allMembers = array_keys($ownerOf);
        $money = [];   // member uei => [obligated, outlays]
        foreach (array_chunk($allMembers, 900) as $chunk) {
            $min = implode(',', array_fill(0, count($chunk), '?'));
            $ms = $pdo->prepare("SELECT recipient_uei u, SUM(total_obligation) o, SUM(total_outlay) ol
                                 FROM usa_award WHERE recipient_uei IN ($min) GROUP BY recipient_uei");
            $ms->execute($chunk);
            foreach ($ms as $mr) $money[$mr['u']] = [(float) $mr['o'], (float) $mr['ol']];
        }
        $usaMoney = [];   // display uei => [obligated|null, outlays|null]
        foreach ($famOf as $u => $fam) {
            $o = null; $ol = null;
            foreach (array_unique($fam) as $m) {
                if (!isset($money[$m])) continue;
                $o = ($o ?? 0) + $money[$m][0];
                $ol = ($ol ?? 0) + $money[$m][1];
            }
            $usaMoney[$u] = [$o, $ol];
        }
    }

    $today = date('Y-m-d');
    foreach ($rows as &$r) {
        $f = $fac[$r['uei']] ?? null;
        $r['ein'] = $f['ein'] ?? null;
        [$r['obligated'], $r['outlays']] = $usaMoney[$r['uei']] ?? [null, null];
        // next FY ends one year after the latest filed FY-end (two for a biennial
        // filer); the audit is due 9 months after that (2 CFR 200.512) — 21 (or 33)
        // clamped months total (lib/Rules.php; naive '+1 year +9 months' rolls
        // Dec-31 to Oct-1).
        $nextDue = str_ends_with((string) ($f['fyp'] ?? ''), '|biennial') ? 33 : 21;
        $due = !empty($f['fye'])
            ? date('Y-m-d', aero_add_months_clamped((string) $f['fye'], $nextDue))
            : null;
        $r['audit_due'] = $due;
        $r['audit_overdue'] = $due !== null && $due < $today;
        $s = $bestSam($candOf[$r['uei']] ?? [$r['uei']]);
        $r['reg_status'] = $s['registration_status'] ?? null;
        $r['reg_expires'] = $s['registration_expiration_date'] ?? null;
    }
    unset($r);
}

// filter-dropdown options — global (not filtered by the current search) and static
// between data syncs, so cache to disk rather than re-run the DISTINCT scans (the
// entity_type one is a full table scan) on every keystroke/search.
$optsFile = dirname(__DIR__) . '/cache/recipient_opts.json';
$opt = (is_file($optsFile) && (time() - filemtime($optsFile)) < 21600) ? cache_get($optsFile) : null;
if ($opt !== null) {
    $types = $opt['entity_types'] ?? [];
    $states = $opt['states'] ?? [];
} else {   // missing, stale, or torn cache — recompute and rewrite
    $types  = $pdo->query("SELECT DISTINCT entity_type FROM entity WHERE latest_audit_year IS NOT NULL AND entity_type IS NOT NULL AND entity_type <> '' ORDER BY entity_type")->fetchAll(PDO::FETCH_COLUMN);
    array_unshift($types, 'State Govt', 'US Territories');  // synthetic facets from the crosswalk
    $states = $pdo->query("SELECT DISTINCT state FROM entity WHERE latest_audit_year IS NOT NULL AND state IS NOT NULL AND state <> '' ORDER BY state")->fetchAll(PDO::FETCH_COLUMN);
    cache_put($optsFile, ['entity_types' => $types, 'states' => $states]);
}

json_out([
    'total'        => $total,
    'limit'        => $limit,
    'shown'        => count($rows),
    'rows'         => $rows,
    'entity_types' => $types,
    'states'       => $states,
    'tiers'        => ['Clean', 'Minimal', 'Moderate', 'Elevated', 'Substantial', 'Severe'],
]);
