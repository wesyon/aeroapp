<?php
declare(strict_types=1);

/**
 * GET /api/convergence?ueis=U1,U2,... — cross-surface risk flags for a set of recipients.
 *
 * For each UEI, which oversight surfaces it ALSO appears on — so the dashboards can show at a
 * glance who lights up in more than one place ("who do I look at first?"). Every lookup is a
 * batched `uei IN (...)` over an indexed column, so one call covers a whole page of leads.
 *
 *   delinquent   — delinquency_preview says likely/probable delinquent (evidence-supported grades)
 *   fs_opinion   — a modified financial-statement opinion (fac_general.gaap_cat <> unmodified)
 *   prog_opinion — a modified major-program compliance opinion (fac_federal_awards A/D/Q, is_major)
 *   chronic      — a chronic repeat finding, 4+ yrs (repeat_preview.chronic)
 *   qc           — a questioned-costs finding (fac_findings.is_questioned_costs)
 *
 * Match is by the UEI as-is: succession-merged governments (delinquency/repeat previews use the
 * canonical UEI) may under-flag a member UEI shown elsewhere — advisory, so a miss is acceptable.
 */

$raw = strtoupper((string) (q_str('ueis') ?? ''));
$ueis = array_values(array_unique(array_filter(
    array_map('trim', explode(',', $raw)),
    static fn ($u) => (bool) preg_match('/^[A-Z0-9]{12}$/', $u))));
if (!$ueis) { json_out(['flags' => []]); }
$ueis = array_slice($ueis, 0, 300);   // one page of leads; keep the IN-lists bounded
$in = implode(',', array_fill(0, count($ueis), '?'));

$flags = [];
foreach ($ueis as $u) $flags[$u] = [];
$mark = static function (array $rows, string $flag) use (&$flags) {
    foreach ($rows as $u) if (isset($flags[$u])) $flags[$u][] = $flag;
};
$pull = static function (string $sql) use ($pdo, $ueis) {
    $st = $pdo->prepare($sql);
    $st->execute($ueis);
    return $st->fetchAll(PDO::FETCH_COLUMN);
};

$mark($pull("SELECT DISTINCT uei FROM delinquency_preview
             WHERE uei IN ($in) AND class IN ('observed','bracketed','committed','allocated','persistent')"),
      'delinquent');
$mark($pull("SELECT DISTINCT auditee_uei FROM fac_general
             WHERE auditee_uei IN ($in) AND is_active = 1 AND gaap_cat <> 'unmodified'"),
      'fs_opinion');
$mark($pull("SELECT DISTINCT g.auditee_uei FROM fac_general g
             JOIN fac_federal_awards fa ON fa.report_id = g.report_id
             WHERE g.auditee_uei IN ($in) AND g.is_active = 1
               AND fa.is_major = 1 AND fa.audit_report_type IN ('Q','A','D')"),
      'prog_opinion');
$mark($pull("SELECT DISTINCT uei FROM repeat_preview WHERE uei IN ($in) AND chronic = 1"),
      'chronic');
$mark($pull("SELECT DISTINCT f.auditee_uei FROM fac_findings f
             JOIN fac_general g ON g.report_id = f.report_id AND g.is_active = 1
             WHERE f.auditee_uei IN ($in) AND f.is_questioned_costs = 1"),
      'qc');

json_out(['flags' => $flags]);
