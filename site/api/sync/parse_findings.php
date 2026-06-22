<?php
declare(strict_types=1);

/**
 * AERO — unpack finding narrative text into structured fields (CLI).
 *
 * Single Audit findings are written to the GAGAS "elements of a finding" template
 * (Criteria / Condition / Cause / Effect / Questioned Costs / Recommendation, plus
 * Context and the auditee's response). This script splits each fac_findings_text
 * row on those labels and extracts scalars the FAC API does not expose — most
 * usefully the questioned-cost dollar amounts — into fac_finding_extract (1:1).
 *
 * It is re-runnable and versioned: bump PARSER_VERSION (and add columns) when the
 * extraction logic grows, then re-run to backfill the new fields over every row.
 *
 * Usage:
 *   php parse_findings.php             # process all finding texts
 *   php parse_findings.php --limit=50  # smoke-test on the first 50
 *   php parse_findings.php --stats     # print coverage stats and exit
 */

ini_set('memory_limit', '1G');
const PARSER_VERSION = 7;
const BATCH = 2000;

$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
Env::load(dirname($root, 2) . '/.env');   // above web root (prod; first-set wins)
Env::load(dirname($root) . '/.env');      // repo root (local dev)
$pdo = Db::connect();

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([^=]+)(?:=(.*))?$/', $a, $m)) $args[$m[1]] = $m[2] ?? true;
}
$limit = isset($args['limit']) ? max(1, (int) $args['limit']) : null;

// One run at a time: two passes would re-parse the same keyset ranges and double
// the write load for nothing. The lock auto-releases when the process exits.
if (!(int) $pdo->query("SELECT GET_LOCK('aero_parse_findings', 0)")->fetchColumn()) {
    fwrite(STDERR, "Another parse_findings.php run is already active; exiting.\n");
    exit(1);
}

create_table($pdo);

if (isset($args['stats'])) { print_stats($pdo); exit; }

// ---- main pass: keyset-paginate fac_findings_text, parse, upsert ----------------
$cols = ['report_id','finding_ref_number','auditee_uei','audit_year','criteria','finding_condition',
         'cause','effect','questioned_costs','recommendation','context','auditee_response',
         'qc_known','qc_likely','qc_amount','qc_stated_zero','qc_basis','sample_size','sections_found',
         'text_len','parser_version','parsed_at'];
$ph = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
$upd = implode(',', array_map(fn ($c) => "$c=VALUES($c)", array_slice($cols, 2)));  // not the PK
$insert = $pdo->prepare("INSERT INTO fac_finding_extract (" . implode(',', $cols) . ") VALUES $ph
                         ON DUPLICATE KEY UPDATE $upd");

$sel = $pdo->prepare(
    "SELECT t.report_id, t.finding_ref_number, t.finding_text, t.auditee_uei, t.audit_year,
            f.is_questioned_costs, g.total_amount_expended
     FROM fac_findings_text t
     LEFT JOIN fac_findings f ON f.report_id = t.report_id AND f.reference_number = t.finding_ref_number
     LEFT JOIN fac_general g  ON g.report_id = t.report_id
     WHERE (t.report_id > ? OR (t.report_id = ? AND t.finding_ref_number > ?))
     ORDER BY t.report_id, t.finding_ref_number LIMIT " . BATCH
);

$lastR = ''; $lastF = ''; $n = 0; $withQc = 0; $t0 = microtime(true);
$now = gmdate('Y-m-d H:i:s');   // all DB timestamps are stored UTC
while (true) {
    $sel->execute([$lastR, $lastR, $lastF]);
    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) break;

    $pdo->beginTransaction();
    foreach ($rows as $r) {
        $flag = $r['is_questioned_costs'] === null ? null : (int) $r['is_questioned_costs'];
        $expended = $r['total_amount_expended'] === null ? null : (int) $r['total_amount_expended'];
        $e = parse_finding((string) $r['finding_text'], $flag, $expended);
        $insert->execute([
            $r['report_id'], $r['finding_ref_number'], $r['auditee_uei'], $r['audit_year'],
            $e['criteria'], $e['condition'], $e['cause'], $e['effect'], $e['questioned_costs'],
            $e['recommendation'], $e['context'], $e['auditee_response'],
            $e['qc_known'], $e['qc_likely'], $e['qc_amount'], $e['qc_stated_zero'], $e['qc_basis'],
            $e['sample_size'], $e['sections_found'], $e['text_len'], PARSER_VERSION, $now,
        ]);
        if ($e['qc_amount'] !== null) $withQc++;
        $lastR = $r['report_id']; $lastF = $r['finding_ref_number'];
        $n++;
        if ($limit !== null && $n >= $limit) break;
    }
    $pdo->commit();

    if ($limit !== null && $n >= $limit) break;
    fprintf(STDERR, "  parsed %d…\r", $n);
}
printf("Parsed %d findings (%d with a questioned-cost amount) in %.1fs [v%d].\n",
    $n, $withQc, microtime(true) - $t0, PARSER_VERSION);
print_stats($pdo);

// =================================================================================

/** Split one finding's text into GAGAS elements + extract scalars.
 *  $flagQc = FAC's structured is_questioned_costs (1/0/null) — used as a prior so a
 *  bare dollar in the QC section is only trusted as a questioned cost when FAC agrees
 *  or the dollar is tied to "questioned cost" wording. */
function parse_finding(string $raw, ?int $flagQc = null, ?int $expended = null): array
{
    $out = [
        'criteria' => null, 'condition' => null, 'cause' => null, 'effect' => null,
        'questioned_costs' => null, 'recommendation' => null, 'context' => null, 'auditee_response' => null,
        'qc_known' => null, 'qc_likely' => null, 'qc_amount' => null, 'qc_stated_zero' => null,
        'qc_basis' => null, 'sample_size' => null, 'sections_found' => 0, 'text_len' => strlen($raw),
    ];
    $text = scrub_text($raw);
    if (trim($text) === '') return $out;

    // Canonical sections we store (key => label alternation, incl. common multi-word
    // variants) plus boundary-only labels (keys prefixed "_") that we DON'T store but
    // use to stop a section from bleeding into a trailing block (Repeat Finding, the
    // Federal Program Information header, etc.). Dashes/quotes are normalized to ASCII
    // by scrub_text() first, so separators below are plain.
    $labels = [
        'criteria'         => 'Criteria(?:\s+or\s+(?:Specific\s+)?Requirements?)?',
        'condition'        => 'Conditions?(?:\s+Found|\s+of\s+(?:the\s+)?Finding)?',
        'cause'            => '(?:Possible\s+Asserted\s+)?Cause(?:\s+of\s+(?:the\s+)?Condition)?',
        'effect'           => '(?:Possible\s+Asserted\s+)?Effect(?:\s+or\s+Potential\s+Effect)?',
        'questioned_costs' => 'Questioned\s+Costs?',
        'recommendation'   => "Recommendations?|Auditor'?s?\s+Recommendations?",
        'context'          => 'Context(?:\s*\/\s*Sampling|\s+and\s+Sampling)?',
        'auditee_response' => "Views?\s+of\s+(?:[A-Za-z'.\-]+\s+){0,4}Officials?"
                            . "|Responsible\s+Officials?'?\s+Response|(?:Auditee|Management|Grantee|Recipient)'?s?\s+(?:Response|Comments?)",
        // boundary-only labels (not stored) — they stop a section from bleeding into a
        // trailing metadata/program block. Frequencies from the corpus heading audit.
        '_repeat'          => 'Repeat\s+Findings?|Repeat\s+of\s+a\s+Prior[\s\-]Year\s+Finding'
                            . '|Identification\s+as\s+a\s+Repeat\s+Finding|Repeat\s+Finding\s+from\s+Prior\s+Year',
        '_prior'           => 'Prior[\s\-]Year\s+(?:Audit\s+)?Findings?(?:\s+Number)?|Prior\s+Findings?',
        '_statement'       => 'Statement\s+of\s+Condition',
        // distinctive multi-word headers only — bare "Federal Program/Agency" etc. are
        // omitted on purpose: they recur in criteria/condition PROSE and (with hard line
        // wraps) would truncate real sections.
        '_proginfo'        => 'Federal\s+Program\s+Information|Information\s+on\s+the\s+Federal\s+Program'
                            . '|Identification\s+of\s+the\s+Federal\s+Program|Federal\s+Awarding\s+Agency|Federal\s+Grantor\s+Name',
        '_listing'         => 'Assistance\s+Listings?\s+Numbers?(?:\s+and\s+Title)?',
        '_funding'         => 'Funding\s+Agency',
        '_faln'            => 'FALN|FAIN|Federal\s+Awards?\s+Identification(?:\s+Number)?(?:\s+and\s+Year)?|Federal\s+Award\s+Numbers?',
        '_pte'             => "Pass[\s\-]?Through\s+(?:Entity|Agency)(?:\s+Name)?|Pass[\s\-]?Through\s+(?:Award|Contract)",
        '_award'           => 'Award\s+(?:Period|Years?|Numbers?(?:\s+and\s+Year)?)|Federal\s+Award\s+Years?',
        '_compliance'      => 'Compliance\s+Requirements?|Type\s+of\s+Compliance(?:\s+Requirement)?',
        '_findtype'        => 'Type\s+of\s+Finding|Finding\s+Type',
        '_meta'            => 'Finding\s+Resolution\s+Status|Contact\s+Person|Statistically\s+Valid\s+Sample'
                            . '|Sample\s+Size(?:\s+Information)?|Anticipated\s+Completion\s+Date',
        '_cap'             => 'Corrective\s+Action(?:\s+Plan)?',
    ];

    // A label hits when it sits at a line start (optionally numbered) OR is followed
    // by a separator — both screen out the same words appearing in prose. Distinctive
    // multi-word boundary labels ("_") also accept being followed by a number/#/( so
    // "Statement of Condition 2022-001" still stops a section from running on.
    $hits = [];
    foreach ($labels as $key => $pat) {
        $b = $key[0] === '_';
        // Canonical labels also accept a colon-less heading style (label + 2+ spaces +
        // a capital/$/digit), which is how some PDF-extracted templates (e.g. HHS Health
        // Center findings: "Criteria  Health centers…") lay out their elements.
        // NB: no '.' in the tail — a label followed by a sentence-ending period is prose
        // (e.g. "...as a pass-through entity."), not a heading; '.' here truncated sections.
        $tailLine = $b ? '[ \t]*(?:[:?\-\t\n#(]|\d)' : '[ \t]*[:?\-\t\n]';
        $tailIn   = $b ? '[ \t]*(?:[:?\-\t#(]|\d)'   : '(?:[ \t]*[:?\-\t]|[ \t]{2,}(?=[A-Z$"(\d]))';
        $re = '/(?:(?<=\n)|^)[ \t]*(?:\d+[.\)]\s*)?(?:' . $pat . ')(?=' . $tailLine . ')'
            . '|\b(?:' . $pat . ')(?=' . $tailIn . ')/iu';
        if (preg_match($re, $text, $m, PREG_OFFSET_CAPTURE)) {
            $hits[] = ['key' => $key, 'start' => $m[0][1], 'len' => strlen($m[0][0])];
        }
    }
    usort($hits, fn ($a, $b) => $a['start'] <=> $b['start']);

    // canonical label patterns, for the bleed-trim below
    $canon = [];
    foreach ($labels as $k => $p) if ($k[0] !== '_') $canon[$k] = $p;

    $stored = 0;
    foreach ($hits as $i => $h) {
        if ($h['key'][0] === '_') continue;                      // boundary-only; not stored
        $from = $h['start'] + $h['len'];
        $to = $hits[$i + 1]['start'] ?? strlen($text);
        $seg = preg_replace('/^[\s:?.\-#()]+/u', '', substr($text, $from, $to - $from));  // drop leading separator
        $seg = collapse_ws((string) $seg);
        // If a missed upstream boundary let this slice run into the next element, cut it
        // at the earliest OTHER canonical label (own label excluded → no self-truncation).
        $others = [];
        foreach ($canon as $k2 => $p2) if ($k2 !== $h['key']) $others[] = $p2;
        $cut = '/\b(?:' . implode('|', $others) . '|Statement\s+of\s+Condition|Repeat\s+Findings?)[ ]?:/i';
        if (preg_match($cut, $seg, $mm, PREG_OFFSET_CAPTURE)) $seg = rtrim(substr($seg, 0, $mm[0][1]));
        if ($seg !== '') { $out[$h['key']] = $seg; $stored++; }
    }
    $out['sections_found'] = $stored;

    // Questioned-cost dollars — scoped to the QC section. The bare "first dollar in
    // the section" heuristic is what produced false positives (it grabs an award size
    // listed by ALN, a contract value bled in from a response block); so a dollar is
    // only accepted as a questioned cost when it is (a) labelled "known questioned
    // costs $X", (b) tied to "questioned cost" wording, or (c) the section has no such
    // wording but FAC's structured flag confirms this IS a questioned-cost finding.
    // qc_basis records which rule fired, for auditability.
    $qc = $out['questioned_costs'] ?? '';
    if ($qc !== '') {
        // explicit known / likely — label may precede the amount ("known QC of $X") or
        // follow it ("$X (known QC)"). The gap stops at ; or ) so a label never reaches
        // past its own clause into a neighbouring figure.
        if (preg_match('/known\s+questioned\s+costs?\b[^$\n;)]{0,40}\$\s?([\d,]+(?:\.\d{2})?)/i', $qc, $m)
            || preg_match('/\$\s?([\d,]+(?:\.\d{2})?)\s*\(?\s*known\s+questioned\s+costs?/i', $qc, $m)) {
            $out['qc_known'] = money($m[1]);
        }
        if (preg_match('/likely\s+questioned\s+costs?\b[^$\n;)]{0,40}\$\s?([\d,]+(?:\.\d{2})?)/i', $qc, $m)
            || preg_match('/\$\s?([\d,]+(?:\.\d{2})?)\s*\(?\s*likely\s+questioned\s+costs?/i', $qc, $m)) {
            $out['qc_likely'] = money($m[1]);
        }
        // a generic dollar tied to "questioned cost(s)" wording (either order), but not
        // the "likely" projection captured above.
        $generic = null;
        if (preg_match('/questioned\s+costs?\b[^$\n;)]{0,25}\$\s?([\d,]+(?:\.\d{2})?)/i', $qc, $m)
            || preg_match('/\$\s?([\d,]+(?:\.\d{2})?)[^$\n;(]{0,25}questioned\s+costs?/i', $qc, $m)) {
            $g = money($m[1]);
            if ($out['qc_likely'] === null || $g !== $out['qc_likely']) $generic = $g;
        }
        // a leading None/$0/N/A/no-questioned-costs ⇒ zero; a leading Unknown/undetermined
        // ⇒ amount genuinely not quantified (null, not a bled-in number).
        $zero = (bool) preg_match('/^\s*(none|n\s*\/\s*a|nil|not\s+applicable|\$\s?0(?:\.0+)?\b)/i', $qc)
              || (bool) preg_match('/\bno\s+(?:known\s+)?question(?:ed)?\s+costs?\b|\bnone\s+(?:noted|identified)\b/i', $qc);
        $unknown = (bool) preg_match('/^\s*(unknown|undetermined|indeterminate|to\s+be\s+determined|cannot\s+be\s+determined|not\s+(?:yet\s+)?determined)/i', $qc);

        // first concrete dollar that isn't the "likely" projection (used by the flagged path)
        $firstConcrete = null;
        if (preg_match_all('/\$\s?([\d,]+(?:\.\d{2})?)/', $qc, $mm)) {
            foreach ($mm[1] as $cand) {
                $v = money($cand);
                if ($out['qc_likely'] === null || $v !== $out['qc_likely']) { $firstConcrete = $v; break; }
            }
        }

        if ($out['qc_known'] !== null) {
            $out['qc_amount'] = $out['qc_known']; $out['qc_basis'] = 'known';
        } elseif ($zero) {
            // a leading None/$0/N/A is the section's answer — it wins over any dollar
            // that follows (which would be bled-in from a glued-on next section).
            $out['qc_amount'] = 0; $out['qc_stated_zero'] = 1; $out['qc_basis'] = 'zero';
        } elseif ($unknown) {
            $out['qc_amount'] = null; $out['qc_basis'] = 'unknown';
        } elseif ($generic !== null) {
            $out['qc_amount'] = $generic; $out['qc_basis'] = 'generic';
            if ($generic === 0) $out['qc_stated_zero'] = 1;
        } elseif ($flagQc === 1 && $firstConcrete !== null) {
            // FAC confirms QC and there's a concrete (non-projection) dollar → trust it.
            $out['qc_amount'] = $firstConcrete; $out['qc_basis'] = 'flagged';
            if ($firstConcrete === 0) $out['qc_stated_zero'] = 1;
        } elseif ($out['qc_likely'] !== null) {
            // only a projected "likely" amount is present — record it under its own basis,
            // NOT as a stated zero. The score excludes 'likely' (a projection, not a known
            // cost); this also makes flag=1 and flag≠1 likely-only cases consistent.
            $out['qc_amount'] = $out['qc_likely']; $out['qc_basis'] = 'likely';
        } elseif ($flagQc === 0) {
            // FAC confirms no questioned cost and the text carries no amount/wording.
            $out['qc_amount'] = 0; $out['qc_stated_zero'] = 1; $out['qc_basis'] = 'none';
        } else {
            // flagQc null (undetermined), or flag=1 with no parseable amount — leave
            // undetermined rather than asserting a zero we cannot support.
            $out['qc_amount'] = null; $out['qc_basis'] = null;
        }

        // Sanity guard: a questioned cost above the audit's ENTIRE federal expended is
        // almost always a misparse (a program/population figure grabbed from bled text).
        // Demote the soft bases (flagged/generic) to 'suspect' and null the amount — the
        // raw QC text is retained for manual review; explicit "known" amounts are kept.
        if ($out['qc_amount'] !== null && $out['qc_amount'] > 0 && $expended !== null && $expended > 0
            && $out['qc_amount'] > $expended
            && in_array($out['qc_basis'], ['flagged', 'generic'], true)) {
            $out['qc_basis'] = 'suspect';
            $out['qc_amount'] = null;
        }
    }

    // FALLBACK (no "Questioned Costs" section detected): a flagged finding often states
    // the amount inline in the prose ("questioned costs of $X") or under a heading the
    // splitter missed ("Questioned Costs $X"). Accept a dollar ONLY when it sits next to
    // (and is not negated by) questioned-cost wording; never override a confirmed no-QC.
    if (($out['questioned_costs'] === null || $out['questioned_costs'] === '')
        && $out['qc_amount'] === null && $flagQc !== 0) {
        // neutralize phrases that say "questioned cost(s)" without being one — the report
        // schedule title and the 2 CFR 200.1 definition — both otherwise snag a nearby $.
        $scan = preg_replace('/schedule\s+of\s+(?:prior(?:\s+year)?\s+)?(?:findings?\s+(?:and|&)\s+)?questioned\s+costs?/i', ' ', $text);
        $scan = preg_replace('/questioned\s+costs?\s+means\b/i', ' ', $scan);
        // a comparison/threshold word in the gap means the $ is a reporting threshold
        // ("greater than $25,000") or a de-minimis bound ("less than $25,000"), not the amount.
        $cmp = '/\b(?:less|greater|more|fewer|lower|higher)\s+than|exceed|in\s+excess\s+of|at\s+least|up\s+to|no\s+more\s+than|threshold|below|above|over|under|each|per\b/i';
        $amt = null;
        if (preg_match_all('/(?<!no )questioned\s+costs?\b([^$\n.;)]{0,40}?)\$\s?([\d,]+(?:\.\d{2})?)/i', $scan, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) { if (!preg_match($cmp, $m[1]) && money($m[2]) > 0) { $amt = money($m[2]); break; } }
        }
        if ($amt === null && preg_match_all('/([^$\n]{0,28})\$\s?([\d,]+(?:\.\d{2})?)([^$\n.;(]{0,40}?)\bquestioned\s+costs?/i', $scan, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) { if (!preg_match($cmp, $m[1] . ' ' . $m[3]) && money($m[2]) > 0) { $amt = money($m[2]); break; } }
        }
        if ($amt !== null) {
            $overExp = $expended !== null && $expended > 0 && $amt > $expended;
            if (!$overExp) { $out['qc_amount'] = $amt; $out['qc_basis'] = 'inline'; }
        }
    }

    // Sample size — only UNAMBIGUOUS sample phrasing where the captured number is the
    // count tested: a verb ("tested/selected N items") or "sample of N". The "N of M"
    // form is deliberately excluded — it is ambiguous (in "2 of 40 items", 2 is the
    // exception count, not the sample). Commas handled; 1990–2100 rejected (years).
    $unit = '(?:transactions|items|samples?|disbursements|employees|payments|expenditures|grantees|'
          . 'files|invoices|vouchers|claims|draws|reports|encounters|cases?|recipients|payrolls?|'
          . 'awards?|loans?|contracts?|checks?|receipts?|selections?)';
    $src = ($out['condition'] ?? '') . ' ' . ($out['context'] ?? '');
    $ss = null;
    if (preg_match('/\b(?:tested|selected|reviewed|examined|sampled|chose)\s+(?:a\s+sample\s+of\s+|a\s+total\s+of\s+)?'
            . '(\d[\d,]{0,7})\s+(?:[a-z\- ]{0,20}\s+)?' . $unit . '/i', $src, $m)
        || preg_match('/\b(?:a\s+|our\s+|the\s+)?(?:sample|selection)\s+of\s+(\d[\d,]{0,7})\b/i', $src, $m)) {
        $v = (int) str_replace(',', '', $m[1]);
        if ($v >= 1 && !($v >= 1990 && $v <= 2100)) $ss = $v;
    }
    $out['sample_size'] = $ss;

    return $out;
}

/** Normalize FAC PDF-extracted text: repair encoding, fold unicode punctuation to
 *  ASCII, drop soft hyphens, unify newlines — without collapsing layout yet. */
function scrub_text(string $t): string
{
    if ($t === '') return $t;
    if (!mb_check_encoding($t, 'UTF-8')) {           // drop invalid bytes so /u regexes don't bail
        $t = @iconv('UTF-8', 'UTF-8//IGNORE', $t);
        if ($t === false) $t = '';
    }
    $t = strtr($t, [
        "\xC2\xA0" => ' ',                                   // nbsp
        "\xC2\xAD" => '',                                    // soft hyphen
        "\xE2\x80\x90" => '-', "\xE2\x80\x91" => '-', "\xE2\x80\x92" => '-',
        "\xE2\x80\x93" => '-', "\xE2\x80\x94" => '-',        // figure/en/em dashes
        "\xE2\x80\x98" => "'", "\xE2\x80\x99" => "'",        // curly single quotes
        "\xE2\x80\x9C" => '"', "\xE2\x80\x9D" => '"',        // curly double quotes
        "\xE2\x80\xA2" => ' ', "\xE2\x80\xA3" => ' ',        // bullets
        "\xE2\x80\xA6" => '...',                             // ellipsis
        "\xEF\xAC\x80" => 'ff', "\xEF\xAC\x81" => 'fi',
        "\xEF\xAC\x82" => 'fl', "\xEF\xAC\x83" => 'ffi', "\xEF\xAC\x84" => 'ffl',
    ]);
    $t = str_replace(["\r\n", "\r"], "\n", $t);
    $t = preg_replace('/[ \t]*\n/u', "\n", $t);              // trim trailing inline space
    return $t;
}

/** Collapse all whitespace (incl. newlines) to single spaces for a stored field. */
function collapse_ws(string $t): string { return trim((string) preg_replace('/\s+/u', ' ', $t)); }

function money(string $s): int { return (int) round((float) str_replace(',', '', $s)); }

function create_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS fac_finding_extract (
            report_id            VARCHAR(40) NOT NULL,
            finding_ref_number   VARCHAR(20) NOT NULL,
            auditee_uei          CHAR(12)    NULL,
            audit_year           SMALLINT    NULL,
            criteria             MEDIUMTEXT  NULL,
            finding_condition    MEDIUMTEXT  NULL,
            cause                MEDIUMTEXT  NULL,
            effect               MEDIUMTEXT  NULL,
            questioned_costs     MEDIUMTEXT  NULL,
            recommendation       MEDIUMTEXT  NULL,
            context              MEDIUMTEXT  NULL,
            auditee_response     MEDIUMTEXT  NULL,
            qc_known             BIGINT      NULL,
            qc_likely            BIGINT      NULL,
            qc_amount            BIGINT      NULL,
            qc_stated_zero       TINYINT(1)  NULL,
            qc_basis             VARCHAR(10) NULL,
            sample_size          INT         NULL,
            sections_found       TINYINT     NULL,
            text_len             INT         NULL,
            parser_version       SMALLINT    NOT NULL,
            parsed_at            DATETIME    NOT NULL,
            PRIMARY KEY (report_id, finding_ref_number),
            KEY idx_fext_uei (auditee_uei),
            KEY idx_fext_year (audit_year),
            KEY idx_fext_qc (qc_amount),
            KEY idx_fext_ver (parser_version),
            CONSTRAINT fk_fext_text FOREIGN KEY (report_id, finding_ref_number)
                REFERENCES fac_findings_text (report_id, finding_ref_number)
                ON UPDATE CASCADE ON DELETE CASCADE
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function print_stats(PDO $pdo): void
{
    $s = $pdo->query(
        "SELECT COUNT(*) n,
            SUM(criteria IS NOT NULL) criteria, SUM(finding_condition IS NOT NULL) cond,
            SUM(cause IS NOT NULL) cause, SUM(effect IS NOT NULL) effect,
            SUM(recommendation IS NOT NULL) recommendation,
            SUM(qc_amount IS NOT NULL) qc_any, SUM(qc_amount > 0) qc_positive,
            SUM(qc_stated_zero = 1) qc_zero, SUM(sample_size IS NOT NULL) sampled,
            ROUND(AVG(sections_found),2) avg_sections
         FROM fac_finding_extract"
    )->fetch(PDO::FETCH_ASSOC);
    echo "fac_finding_extract coverage:\n";
    foreach ($s as $k => $v) printf("  %-14s %s\n", $k, $v);
    echo "qc_basis (how each qc_amount was derived):\n";
    foreach ($pdo->query("SELECT COALESCE(qc_basis,'(null)') b, COUNT(*) n, SUM(qc_amount>0) pos
                          FROM fac_finding_extract GROUP BY qc_basis ORDER BY n DESC") as $r) {
        printf("  %-10s %6d rows  (%d with \$>0)\n", $r['b'], $r['n'], $r['pos']);
    }
}
