<?php
declare(strict_types=1);

/**
 * Pure, dependency-free value normalizers shared by the FAC sync.
 *
 * Type normalization happens at INGEST (not in the API): FAC "Y"/"N" strings ->
 * TINYINT(1); string dates (ISO or US m/d/Y) -> DATE; string years -> SMALLINT;
 * whole-dollar amounts -> numeric. These are the trickiest bits of the pipeline,
 * so they live here on their own to stay unit-testable (see api/tests/).
 *
 * Defined as plain functions (kept their historical n_* names so call sites are
 * unchanged) and guarded so the file is safe to include more than once.
 */
if (!function_exists('n_s')) {
    /** Trim to a non-empty string, or NULL. */
    function n_s($v): ?string
    {
        if ($v === null) return null;
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    /** FAC "Y"/"N" (and TRUE/FALSE/1/0/T/F) -> 1/0; blanks and "N/A" -> NULL. */
    function n_yn($v): ?int
    {
        if ($v === null) return null;
        $t = strtoupper(trim((string) $v));
        if ($t === '' || $t === 'N/A') return null;
        if ($t[0] === 'Y' || $t === 'TRUE' || $t === '1' || $t === 'T') return 1;
        if ($t[0] === 'N' || $t === 'FALSE' || $t === '0' || $t === 'F') return 0;
        return null;
    }

    /** First 4 chars as an int year (handles "2023", "2023-09-30", etc.); blank -> NULL. */
    function n_yr($v): ?int
    {
        if ($v === null || $v === '') return null;
        return (int) substr((string) $v, 0, 4);
    }

    /** Strip currency formatting to a numeric (int|float); empty/garbage -> NULL. */
    function n_num($v)
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return $v + 0;
        $c = preg_replace('/[^0-9.\-]/', '', (string) $v);
        return ($c === '' || $c === '-' || $c === '.') ? null : $c + 0;
    }

    /** A valid UEI is exactly 12 alphanumerics; anything else (e.g. FAC's
     * "GSA_MIGRATION" placeholder on migrated legacy audits) becomes NULL. */
    function n_uei($v): ?string
    {
        $v = n_s($v);
        return ($v !== null && preg_match('/^[A-Za-z0-9]{12}$/', $v)) ? $v : null;
    }

    /** Parse ISO (YYYY-MM-DD...) or US (m/d/Y) dates to a DATE string; else NULL. */
    function n_d($v): ?string
    {
        if ($v === null) return null;
        $v = trim((string) $v);
        if ($v === '') return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return substr($v, 0, 10);
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[1], (int) $m[2]);
        }
        $t = strtotime($v);
        return $t ? date('Y-m-d', $t) : null;
    }
}
