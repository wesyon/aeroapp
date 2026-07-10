<?php
declare(strict_types=1);

/**
 * AERO — build the subaward edge aggregate (CLI, LOCAL ONLY).
 *
 * Collapses the ~2.8 GB sam_assistance_subaward detail table into subaward_edge:
 * one row per (prime_entity_uei, sub_vendor_uei) pair touching an HHS recipient on
 * either side, with the subaward count / total $ / date span / ALN set rolled up.
 * ~122k rows / ~29 MB — small enough to ship to prod, where the detail table can't
 * fit the 3 GB quota. The entity-profile Subrecipients tab reads it both ways:
 *   prime_entity_uei = X  -> funds X passed DOWN to subrecipients (sent)
 *   sub_vendor_uei   = X  -> funds X received as a subrecipient (received)
 *
 * Scope (the "keep" set, either endpoint): aero_score.is_hhs = 1, plus every UEI in
 * the state_uei crosswalk groups (a multi-UEI state government holds subawards under
 * former UEIs that have no aero_score row of their own).
 *
 * SAFETY: this runs ONLY where the detail table is populated (local). If
 * sam_assistance_subaward is empty it ABORTS WITHOUT touching subaward_edge, so it
 * can never truncate a good prod copy (prod has no detail table). The prod nightly
 * does not call it; subaward_edge reaches prod via deploy.ps1 -PushTable.
 *
 * Usage:
 *   php build_subaward_edge.php
 *   php build_subaward_edge.php --stats   # print current edge-table stats and exit
 */

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

if (isset($args['stats'])) {
    $exists = $pdo->query("SHOW TABLES LIKE 'subaward_edge'")->fetchColumn();
    if (!$exists) { echo "subaward_edge does not exist (run a build first)\n"; exit(0); }
    $r = $pdo->query("SELECT COUNT(*) edges, COUNT(DISTINCT prime_entity_uei) primes,
                             COUNT(DISTINCT sub_vendor_uei) subs, SUM(subawards) subawards,
                             ROUND(SUM(total_amount)/1e9,1) total_b
                      FROM subaward_edge")->fetch(PDO::FETCH_ASSOC);
    printf("edges=%s  primes=%s  subs=%s  subawards=%s  total=\$%sB\n",
        number_format((int) $r['edges']), number_format((int) $r['primes']),
        number_format((int) $r['subs']), number_format((int) $r['subawards']), $r['total_b']);
    exit(0);
}

// One run at a time (the GROUP BY scan is heavy); auto-releases on exit.
if (!(int) $pdo->query("SELECT GET_LOCK('aero_build_subaward_edge', 0)")->fetchColumn()) {
    fwrite(STDERR, "Another build_subaward_edge.php run is already active; exiting.\n");
    exit(0);
}

// SAFETY GATE: never run against an empty detail table (would wipe a good prod copy).
$srcRows = (int) $pdo->query("SELECT COUNT(*) FROM sam_assistance_subaward")->fetchColumn();
if ($srcRows === 0) {
    fwrite(STDERR, "sam_assistance_subaward is EMPTY — aborting without touching subaward_edge.\n");
    fwrite(STDERR, "(This table is built locally where the detail data lives, then pushed to prod.)\n");
    exit(1);
}
echo "source rows (sam_assistance_subaward): " . number_format($srcRows) . "\n";

$startedAt = gmdate('Y-m-d H:i:s');   // sync_log timestamps are UTC
$t0 = microtime(true);

// 1) Keep-set: HHS recipients + every crosswalk-group UEI (state-government succession).
// A REGULAR (not TEMPORARY) table: the aggregate query references it twice (prime IN ..
// OR sub IN ..) and MySQL can't reopen a TEMPORARY table within one statement (err 1137).
$pdo->exec("DROP TABLE IF EXISTS _edge_keep");
$pdo->exec("CREATE TABLE _edge_keep (uei CHAR(12) NOT NULL PRIMARY KEY) ENGINE=InnoDB");
$ins = $pdo->prepare("INSERT IGNORE INTO _edge_keep (uei) VALUES (?)");

$keep = [];
foreach ($pdo->query("SELECT uei FROM aero_score WHERE is_hhs = 1 AND uei IS NOT NULL") as $r) {
    $keep[strtoupper((string) $r['uei'])] = true;
}
foreach ($pdo->query("SELECT ueis FROM state_uei") as $r) {
    foreach (preg_split('/[\s,]+/', (string) $r['ueis']) as $u) {
        $u = strtoupper(trim($u));
        if ($u !== '' && preg_match('/^[A-Z0-9]{12}$/', $u)) $keep[$u] = true;
    }
}
$pdo->beginTransaction();
foreach (array_keys($keep) as $u) $ins->execute([$u]);
$pdo->commit();
echo "keep-set (HHS recipients + crosswalk UEIs): " . number_format(count($keep)) . "\n";

// 1b) Rollup-family members of kept parents: additional UEIs declared on the parent's
// SF-SAC + curated component links (entity_related_uei). Component agencies file FSRS
// subawards under their OWN UEIs (e.g. PennDOT under the Commonwealth of PA); without
// them here, a component's edges to non-HHS subs are dropped and the parent profile's
// Passthrough tab undercounts. Scoped to KEPT parents so components of out-of-scope
// auditees don't balloon the table.
$pdo->exec(
    "INSERT IGNORE INTO _edge_keep (uei)
     SELECT DISTINCT UPPER(au.additional_uei) FROM fac_additional_ueis au
     JOIN _edge_keep k ON k.uei = au.auditee_uei
     WHERE au.additional_uei REGEXP '^[A-Za-z0-9]{12}$'"
);
$pdo->exec(
    "INSERT IGNORE INTO _edge_keep (uei)
     SELECT DISTINCT UPPER(er.related_uei) FROM entity_related_uei er
     JOIN _edge_keep k ON k.uei = er.uei
     WHERE er.related_uei REGEXP '^[A-Za-z0-9]{12}$'"
);
$keepTotal = (int) $pdo->query("SELECT COUNT(*) FROM _edge_keep")->fetchColumn();
echo "keep-set incl. rollup-family members: " . number_format($keepTotal) . "\n";

// 2) (Re)create the edge table fresh with the canonical schema (matches the migration).
//    DROP+CREATE so a schema change (e.g. the year dimension) always takes locally; only
//    runs local, and the prod copy is replaced wholesale by deploy.ps1 -PushTable anyway.
//    Grain is (prime, sub, YEAR) so the Passthrough tab can filter by subaward year.
$pdo->exec("DROP TABLE IF EXISTS subaward_edge");
$pdo->exec(
    "CREATE TABLE subaward_edge (
        prime_entity_uei CHAR(12)      NOT NULL,
        sub_vendor_uei   CHAR(12)      NOT NULL,
        year             SMALLINT      NOT NULL,
        prime_name       VARCHAR(255)  NULL,
        sub_name         VARCHAR(255)  NULL,
        subawards        INT           NOT NULL DEFAULT 0,
        total_amount     DECIMAL(18,2) NULL,
        max_amount       DECIMAL(18,2) NULL,
        alns             VARCHAR(255)  NULL,
        PRIMARY KEY (prime_entity_uei, sub_vendor_uei, year),
        KEY idx_subedge_sub (sub_vendor_uei)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

echo "aggregating edges (this scans the detail table — a minute or two)...\n";
// year = subaward date, falling back to obligation/submission so every row gets a year
// (the PK needs a non-null year). ALNs are concatenated per (pair, year) and split/deduped
// downstream in the API.
//
// SANITATION (2026-07-08 — FSRS $ pollution made fleet-level sums nonsense):
//   1. AMENDMENT DEDUPE — the same subaward appears once per report version; summing
//      them multiplies the dollars. Keep only the LATEST version of each
//      (prime_award_key, subaward_number); rows with no usable key stay themselves.
//   2. PLAUSIBILITY — drop rows claiming a subaward LARGER than the prime award's own
//      total funding (e.g. a $58B "subaward" to a community action agency). Rule only
//      fires when total_fed_funding_amount is present and positive.
$pdo->exec(
    "INSERT INTO subaward_edge
        (prime_entity_uei, sub_vendor_uei, year, prime_name, sub_name,
         subawards, total_amount, max_amount, alns)
     SELECT t.prime_entity_uei, t.sub_vendor_uei, t.yr,
            MAX(t.prime_entity_name), MAX(t.sub_vendor_name),
            COUNT(*), SUM(t.subaward_amount), MAX(t.subaward_amount),
            LEFT(GROUP_CONCAT(DISTINCT t.aln ORDER BY t.aln SEPARATOR ', '), 255)
     FROM (
        SELECT s.prime_entity_uei, s.sub_vendor_uei, s.prime_entity_name, s.sub_vendor_name,
               s.subaward_amount, s.total_fed_funding_amount, s.aln,
               YEAR(COALESCE(s.subaward_date, s.base_obligation_date, s.submitted_date)) yr,
               ROW_NUMBER() OVER (
                   PARTITION BY COALESCE(NULLIF(s.prime_award_key,''), NULLIF(s.fain,''), CAST(s.id AS CHAR)),
                                COALESCE(NULLIF(s.subaward_number,''), CAST(s.id AS CHAR))
                   ORDER BY s.report_updated_date DESC, s.submitted_date DESC, s.id DESC) rn
        FROM sam_assistance_subaward s
        WHERE s.prime_entity_uei IS NOT NULL AND s.prime_entity_uei <> ''
          AND s.sub_vendor_uei  IS NOT NULL AND s.sub_vendor_uei  <> ''
          AND YEAR(COALESCE(s.subaward_date, s.base_obligation_date, s.submitted_date))
                BETWEEN 2022 AND YEAR(CURDATE())
          AND (s.prime_entity_uei IN (SELECT uei FROM _edge_keep)
            OR s.sub_vendor_uei  IN (SELECT uei FROM _edge_keep))
     ) t
     WHERE t.rn = 1
       AND (t.total_fed_funding_amount IS NULL OR t.total_fed_funding_amount <= 0
            OR t.subaward_amount <= t.total_fed_funding_amount)
     GROUP BY t.prime_entity_uei, t.sub_vendor_uei, t.yr"
);
$pdo->exec("DROP TABLE IF EXISTS _edge_keep");

$st = $pdo->query("SELECT COUNT(*) edges, COUNT(DISTINCT prime_entity_uei) primes,
                          COUNT(DISTINCT sub_vendor_uei) subs
                   FROM subaward_edge")->fetch(PDO::FETCH_ASSOC);
printf("built subaward_edge: %s edges (%s distinct primes, %s distinct subs) in %.1fs\n",
    number_format((int) $st['edges']), number_format((int) $st['primes']),
    number_format((int) $st['subs']), microtime(true) - $t0);

// 3) Entity-type lookup for the counterparties shown in the tab, derived from FSRS
//    sub_business_types (94% populated). Classifies each UEI into Government / Higher Ed /
//    Nonprofit / For-Profit / Other and flags whether it OWES a Single Audit (govt, higher
//    ed, nonprofit do — 2 CFR 200.501; for-profits are exempt). This lets the >=$1M-no-audit
//    flag suppress for-profit subs (e.g. an LLC contractor) instead of over-flagging them.
//    Scoped to UEIs appearing in subaward_edge in either role; precedence govt>highered>np>fp.
$t1 = microtime(true);
$pdo->exec("DROP TABLE IF EXISTS subaward_entity_type");
$pdo->exec(
    "CREATE TABLE subaward_entity_type (
        uei              CHAR(12)    NOT NULL PRIMARY KEY,
        entity_type      VARCHAR(16) NULL,
        audit_applicable TINYINT     NOT NULL DEFAULT 0
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
echo "classifying counterparty entity types from sub_business_types...\n";
$GOV  = "'U.S. Local Government','US Local Government','School District','County','U.S. State Government',"
      . "'City','Municipality','Township','Council of Governments','Local Government Owned','Planning Commission',"
      . "'Housing Authorities Public/Tribal','Transit Authority','Airport Authority','Inter-Municipal','Interstate',"
      . "'Special District Government','Indian/Native American Tribal Government (Federally Recognized)',"
      . "'Indian/Native American Tribal Government (Other than Federally Recognized)',"
      . "'Indian/Native American Tribal Designated Organization','U.S. Government','Federal Government'";
$HIED = "'Educational Institution','State Controlled Institution of Higher Learning','Private University or College',"
      . "'1862 Land Grant College','1890 Land Grant College','1994 Land Grant College','Minority Institution',"
      . "'Hispanic Servicing Institution','School of Forestry','Veterinary College','Minority Serving Institution',"
      . "'Historically Black Colleges and Universities (HBCUs)','Tribally Controlled College',"
      . "'Alaska Native and Native Hawaiian Serving Institutions'";
$NP   = "'Nonprofit Organization','Non-Profit Organization','Other Not-for-Profit Organization',"
      . "'Other Not For Profit Organization','Community Development Corporation','Foundation','Domestic Shelter'";
$FP   = "'For-Profit Organization','For Profit Organization','Limited Liability Company',"
      . "'Corporate Entity (Not Tax Exempt)','Sole Proprietorship','Partnership or Limited Liability Partnership',"
      . "'Manufacturer of Goods','Small Business','Minority-Owned business','Woman-Owned Business',"
      . "'Women-Owned Business','Black American Owned','Hispanic American Owned','Asian-Pacific American Owned',"
      . "'Native American Owned','Veteran Owned','Service Disabled Veteran Owned',"
      . "'Subcontinent Asian (Asian-Indian) American Owned'";
$pdo->exec(
    "INSERT INTO subaward_entity_type (uei, entity_type, audit_applicable)
     SELECT uei,
            CASE WHEN gov THEN 'Government' WHEN hied THEN 'Higher Ed' WHEN np THEN 'Nonprofit'
                 WHEN fp THEN 'For-Profit' ELSE 'Other' END,
            CASE WHEN gov OR hied OR np THEN 1 ELSE 0 END
     FROM (
        SELECT s.sub_vendor_uei uei,
               MAX(jt.nm IN ($GOV))  gov,
               MAX(jt.nm IN ($HIED)) hied,
               MAX(jt.nm IN ($NP))   np,
               MAX(jt.nm IN ($FP))   fp
        FROM sam_assistance_subaward s
        JOIN JSON_TABLE(s.sub_business_types, '$[*]' COLUMNS (nm VARCHAR(200) PATH '$.name')) jt
        WHERE s.sub_vendor_uei IN (SELECT sub_vendor_uei FROM subaward_edge
                                   UNION SELECT prime_entity_uei FROM subaward_edge)
        GROUP BY s.sub_vendor_uei
     ) t"
);
$tc = $pdo->query("SELECT COUNT(*) n, SUM(audit_applicable) a FROM subaward_entity_type")->fetch(PDO::FETCH_ASSOC);
printf("built subaward_entity_type: %s UEIs (%s audit-applicable) in %.1fs\n",
    number_format((int) $tc['n']), number_format((int) $tc['a']), microtime(true) - $t1);

// Record freshness for the Data Status console (source 'subedge'). Dates the aggregate on
// THIS machine; deploy.ps1 -PushTable writes the same row on prod when it ships the table.
$pdo->prepare(
    "INSERT INTO sync_log (source, table_name, rows_upserted, status, started_at, finished_at)
     VALUES ('subedge', 'subaward_edge', ?, 'ok', ?, UTC_TIMESTAMP())"
)->execute([(int) $st['edges'], $startedAt]);
