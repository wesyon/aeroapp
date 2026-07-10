<?php
declare(strict_types=1);
/**
 * GET /api/crosscheck — SAM ↔ FAC cross-source comparison, fleet-wide.
 *
 * Returns the RAW paired identity fields (latest ACTIVE audit filing vs SAM registration,
 * joined on UEI) for every audited entity, one compact row each. The match VERDICTS are
 * deliberately NOT computed here: the frontend applies the exact same normalization
 * functions the per-entity Cross-Source Check card uses (web/src/crosscheck.jsx), so the
 * dashboard and the card can never disagree about what counts as a match.
 *
 * ~27k rows of short strings (~700 KB gzipped) — one indexed window-function pass, no cache.
 * Keys are abbreviated to keep the payload lean: f* = FAC (audit), s* = SAM (registration).
 */

$rows = [];
$stmt = $pdo->query(
    "SELECT t.uei,
            t.fn, t.ft, t.fl1, t.fcy, t.fst, t.fzp, t.ffy, t.ay,
            s.legal_business_name sn, s.dba_name sd,
            s.physical_address_line1 sl1, s.physical_address_city scy,
            s.physical_address_state sst, s.physical_address_zip szp,
            s.fiscal_year_end_close_date sfy, s.entity_structure sty,
            s.registration_status rs, s.entity_start_date es
     FROM (
        SELECT auditee_uei uei, auditee_name fn, entity_type ft,
               auditee_address_line_1 fl1, auditee_city fcy, auditee_state fst, auditee_zip fzp,
               fy_end_date ffy, audit_year ay,
               ROW_NUMBER() OVER (PARTITION BY auditee_uei
                                  ORDER BY audit_year DESC, fac_accepted_date DESC, report_id DESC) rn
        FROM fac_general
        WHERE is_active = 1 AND auditee_uei IS NOT NULL
     ) t
     LEFT JOIN sam_entity s ON s.uei = t.uei
     WHERE t.rn = 1"
);
foreach ($stmt as $r) {
    $rows[] = [
        'uei' => $r['uei'],
        'fn' => $r['fn'],  'ft' => $r['ft'],
        'fl1' => $r['fl1'], 'fcy' => $r['fcy'], 'fst' => $r['fst'], 'fzp' => $r['fzp'],
        'ffy' => $r['ffy'], 'ay' => $r['ay'] !== null ? (int) $r['ay'] : null,
        'sn' => $r['sn'],  'sd' => $r['sd'],
        'sl1' => $r['sl1'], 'scy' => $r['scy'], 'sst' => $r['sst'], 'szp' => $r['szp'],
        'sfy' => $r['sfy'], 'sty' => $r['sty'], 'rs' => $r['rs'], 'es' => $r['es'],
    ];
}

json_out(['count' => count($rows), 'rows' => $rows]);
