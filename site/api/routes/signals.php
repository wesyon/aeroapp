<?php
declare(strict_types=1);
/**
 * GET /api/signals?action=summary|list|entity — the AERO Signals review console (read-only).
 *
 *   summary            registry + per-code live/suppressed counts, tier rollups, convergence
 *                      histogram, build freshness.
 *   list&code=CODE     entities tripping one signal (live), with cited evidence.
 *   list&min_signals=N the convergence worklist from signal_entity (entities with >=N live signals).
 *   entity&uei=UEI     one entity's full caseboard: every flag (live + suppressed) with evidence.
 *
 * INTERNAL-FIRST: the whole route is gated to the local console (same gate as the admin
 * surface). It 404s off-localhost / when APP_ENV=prod, so the page stays internal until
 * individual signals are graduated to public.
 */

if (!is_local_request()) {
    json_out(['error' => 'not found'], 404);
}
require_once dirname(__DIR__) . '/lib/Signals.php';

$action = q_str('action') ?? 'summary';

// signal_flag may not exist before the first build — fail soft with a clear message.
if (!$pdo->query("SHOW TABLES LIKE 'signal_flag'")->fetchColumn()) {
    json_out(['error' => 'signals not built', 'hint' => 'run php api/sync/build_signals.php'], 503);
}

$decode = static fn ($j) => $j === null ? null : json_decode((string) $j, true);
$meta = static function (string $code): array {
    $m = Signals::REGISTRY[$code] ?? null;
    return $m ? ['label' => $m['label'], 'blurb' => $m['blurb'], 'tier' => $m['tier'],
        'severity' => $m['severity'], 'visibility' => $m['visibility'], 'implemented' => $m['implemented']] : [];
};

if ($action === 'summary') {
    $counts = [];
    foreach ($pdo->query("SELECT code, SUM(suppressed=0) live, SUM(suppressed=1) supp FROM signal_flag GROUP BY code") as $r) {
        $counts[$r['code']] = ['live' => (int) $r['live'], 'suppressed' => (int) $r['supp']];
    }
    $registry = [];
    foreach (Signals::REGISTRY as $code => $m) {
        $registry[] = ['code' => $code] + $meta($code)
            + ['live' => $counts[$code]['live'] ?? 0, 'suppressed' => $counts[$code]['suppressed'] ?? 0];
    }
    $tiers = $pdo->query(
        "SELECT tier, COUNT(*) flags, COUNT(DISTINCT uei) entities FROM signal_flag WHERE suppressed=0 GROUP BY tier")
        ->fetchAll(PDO::FETCH_ASSOC);
    $hist = $pdo->query(
        "SELECT n_signals, COUNT(*) entities FROM signal_entity GROUP BY n_signals ORDER BY n_signals DESC")
        ->fetchAll(PDO::FETCH_ASSOC);
    $fresh = $pdo->query(
        "SELECT finished_at, rows_upserted FROM sync_log WHERE source='signals' ORDER BY id DESC LIMIT 1")
        ->fetch(PDO::FETCH_ASSOC) ?: null;
    $ents = (int) $pdo->query("SELECT COUNT(*) FROM signal_entity")->fetchColumn();
    json_out([
        'registry' => $registry, 'tiers' => $tiers, 'convergence' => $hist,
        'entities_flagged' => $ents, 'built_at' => $fresh['finished_at'] ?? null,
        'total_flags' => (int) ($fresh['rows_upserted'] ?? 0), 'generated_at' => date('c'),
    ]);
}

if ($action === 'list') {
    $limit = q_int('limit', 100, 1, 500);
    $state = q_str('state');
    if ($state !== null && !preg_match('/^[A-Za-z]{2}$/', $state)) $state = null;
    $state = $state !== null ? strtoupper($state) : null;

    // convergence worklist
    if (q_str('min_signals') !== null) {
        $min = q_int('min_signals', 2, 1, 25);
        $w = ['se.n_signals >= ?']; $p = [$min];
        if ($state) { $w[] = 'e.state = ?'; $p[] = $state; }
        $sql = "SELECT se.uei, se.n_signals, se.n_rule, se.n_network, se.n_stat, se.top_severity, se.codes,
                       e.display_name name, e.state, e.federal_latest
                FROM signal_entity se JOIN entity e ON e.uei = se.uei
                WHERE " . implode(' AND ', $w) . "
                ORDER BY se.n_signals DESC, se.n_rule DESC, e.federal_latest DESC LIMIT $limit";
        $st = $pdo->prepare($sql); $st->execute($p);
        $rows = [];
        foreach ($st as $r) {
            $r['federal_latest'] = $r['federal_latest'] !== null ? (int) $r['federal_latest'] : null;
            $r['n_signals'] = (int) $r['n_signals']; $r['n_rule'] = (int) $r['n_rule'];
            $r['codes'] = array_values(array_unique($decode($r['codes']) ?: []));
            $rows[] = $r;
        }
        json_out(['mode' => 'convergence', 'min_signals' => $min, 'rows' => $rows]);
    }

    // single-signal drill-down
    $code = q_str('code');
    if ($code === null || !isset(Signals::REGISTRY[$code])) {
        json_out(['error' => 'unknown or missing code', 'codes' => array_keys(Signals::REGISTRY)], 400);
    }
    $w = ['sf.code = ?', 'sf.suppressed = 0']; $p = [$code];
    if ($state) { $w[] = 'e.state = ?'; $p[] = $state; }
    $sql = "SELECT sf.uei, sf.scope, sf.magnitude, sf.evidence, e.display_name name, e.state,
                   e.federal_latest, se.n_signals
            FROM signal_flag sf
            JOIN entity e ON e.uei = sf.uei
            LEFT JOIN signal_entity se ON se.uei = sf.uei
            WHERE " . implode(' AND ', $w) . "
            ORDER BY sf.magnitude DESC, e.federal_latest DESC LIMIT $limit";
    $st = $pdo->prepare($sql); $st->execute($p);
    $rows = [];
    foreach ($st as $r) {
        $r['magnitude'] = $r['magnitude'] !== null ? (float) $r['magnitude'] : null;
        $r['federal_latest'] = $r['federal_latest'] !== null ? (int) $r['federal_latest'] : null;
        $r['n_signals'] = $r['n_signals'] !== null ? (int) $r['n_signals'] : 1;
        $r['evidence'] = $decode($r['evidence']);
        $rows[] = $r;
    }
    json_out(['mode' => 'signal', 'code' => $code] + $meta($code) + ['rows' => $rows]);
}

if ($action === 'entity') {
    $uei = q_str('uei');
    if ($uei === null || !preg_match('/^[A-Za-z0-9]{12}$/', $uei)) {
        json_out(['error' => 'valid uei required'], 400);
    }
    $uei = strtoupper($uei);
    $hdr = $pdo->prepare(
        "SELECT uei, display_name, entity_type, state, federal_latest, latest_audit_year, is_hhs FROM entity WHERE uei = ?");
    $hdr->execute([$uei]);
    $entity = $hdr->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($entity) {
        $entity['federal_latest'] = $entity['federal_latest'] !== null ? (int) $entity['federal_latest'] : null;
        $entity['latest_audit_year'] = $entity['latest_audit_year'] !== null ? (int) $entity['latest_audit_year'] : null;
        $entity['is_hhs'] = (int) $entity['is_hhs'];
    }
    $st = $pdo->prepare(
        "SELECT code, scope, tier, severity, magnitude, evidence, suppressed
         FROM signal_flag WHERE uei = ?
         ORDER BY tier ASC, FIELD(severity,'rule_violation','data_integrity','network','statistical'), magnitude DESC");
    $st->execute([$uei]);
    $flags = [];
    foreach ($st as $r) {
        $flags[] = [
            'code' => $r['code'], 'scope' => $r['scope'], 'tier' => (int) $r['tier'], 'severity' => $r['severity'],
            'magnitude' => $r['magnitude'] !== null ? (float) $r['magnitude'] : null,
            'suppressed' => (int) $r['suppressed'], 'evidence' => $decode($r['evidence']),
        ] + $meta($r['code']);
    }
    json_out(['uei' => $uei, 'entity' => $entity, 'flags' => $flags]);
}

json_out(['error' => 'unknown action', 'actions' => ['summary', 'list', 'entity']], 400);
