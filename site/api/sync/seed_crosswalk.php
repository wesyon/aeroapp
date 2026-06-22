<?php
declare(strict_types=1);

/**
 * AERO — seed the state/UEI crosswalk (CLI).
 * For each state/territory, finds the state-government recipient(s) in FAC by name
 * and records their UEI(s). The table is hand-correctable in the UI, so re-runs
 * PRESERVE any state that already has UEIs (hand-edited or previously seeded) and
 * only fill empty/missing states; pass --force to overwrite everything.
 *
 *   php api/sync/seed_crosswalk.php [--force]
 */

$root = dirname(__DIR__);
require $root . '/lib/Env.php';
require $root . '/lib/Db.php';
Env::load(dirname($root, 2) . '/.env');   // above web root (prod; first-set wins)
Env::load(dirname($root) . '/.env');      // repo root (local dev)
$pdo = Db::connect();
$force = in_array('--force', $argv, true);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS state_uei (
        state_code CHAR(2) NOT NULL, label VARCHAR(100) NULL, ueis TEXT NULL,
        note VARCHAR(255) NULL, updated_at DATETIME NULL, PRIMARY KEY (state_code)
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// code => [display label, exact FAC auditee_name(s) to match]
$STATES = [
    'AL' => ['State of Alabama'], 'AK' => ['State of Alaska'], 'AZ' => ['State of Arizona'],
    'AR' => ['State of Arkansas'], 'CA' => ['State of California'], 'CO' => ['State of Colorado'],
    'CT' => ['State of Connecticut'], 'DE' => ['State of Delaware'], 'DC' => ['District of Columbia'],
    'FL' => ['State of Florida'], 'GA' => ['State of Georgia'], 'HI' => ['State of Hawaii'],
    'ID' => ['State of Idaho'], 'IL' => ['State of Illinois'], 'IN' => ['State of Indiana'],
    'IA' => ['State of Iowa'], 'KS' => ['State of Kansas'], 'KY' => ['Commonwealth of Kentucky'],
    'LA' => ['State of Louisiana'], 'ME' => ['State of Maine'], 'MD' => ['State of Maryland'],
    'MA' => ['Commonwealth of Massachusetts'], 'MI' => ['State of Michigan'], 'MN' => ['State of Minnesota'],
    'MS' => ['State of Mississippi'], 'MO' => ['State of Missouri'], 'MT' => ['State of Montana'],
    'NE' => ['State of Nebraska'], 'NV' => ['State of Nevada'], 'NH' => ['State of New Hampshire'],
    'NJ' => ['State of New Jersey'], 'NM' => ['State of New Mexico'], 'NY' => ['State of New York'],
    'NC' => ['State of North Carolina'], 'ND' => ['State of North Dakota'], 'OH' => ['State of Ohio'],
    'OK' => ['State of Oklahoma'], 'OR' => ['State of Oregon'], 'PA' => ['Commonwealth of Pennsylvania'],
    'RI' => ['State of Rhode Island'], 'SC' => ['State of South Carolina'], 'SD' => ['State of South Dakota'],
    'TN' => ['State of Tennessee'], 'TX' => ['State of Texas'], 'UT' => ['State of Utah'],
    'VT' => ['State of Vermont'], 'VA' => ['Commonwealth of Virginia'], 'WA' => ['State of Washington'],
    'WV' => ['State of West Virginia'], 'WI' => ['State of Wisconsin'], 'WY' => ['State of Wyoming'],
    'PR' => ['Commonwealth of Puerto Rico'], 'MP' => ['Commonwealth of the Northern Mariana Islands'],
    // territories whose FAC name often differs from the common label — try a few
    'VI' => ['U.S. Virgin Islands', 'United States Virgin Islands', 'Government of the U.S. Virgin Islands', 'Government of the United States Virgin Islands'],
    'GU' => ['Government of Guam', 'Guam', 'Territory of Guam'],
    'AS' => ['Territory of American Samoa', 'American Samoa', 'American Samoa Government', 'Government of American Samoa'],
];

// Explicit UEIs for state govts whose FAC name doesn't match the common pattern
// (suffixes like "C/O ...", "/State Accounting Office", or "Government of ...").
$OVERRIDE = [
    'DC' => ['WK2NXW3LS3L3', 'SJ9QS4MQKJ17'],
    'GA' => ['R96RFDTXEFG9'],
    'HI' => ['HKK5YY1DWYM3'],
    'NM' => ['K49NN52HU4L7'],
    'PR' => ['C6QUF1FP1HK8'],
    'TX' => ['JCJBPTJXYXH9'],
    'WA' => ['GCU8PW8ZDXN8'],
];

$findExact = $pdo->prepare(
    "SELECT DISTINCT auditee_uei FROM fac_general
     WHERE auditee_name = ? AND auditee_uei IS NOT NULL AND auditee_uei REGEXP '^[A-Za-z0-9]{12}$'"
);
$up = $pdo->prepare(
    "INSERT INTO state_uei (state_code, label, ueis, note, updated_at)
     VALUES (:c, :l, :u, :n, UTC_TIMESTAMP())
     ON DUPLICATE KEY UPDATE label=VALUES(label), ueis=VALUES(ueis), note=VALUES(note), updated_at=VALUES(updated_at)"
);

// states that already have UEIs (hand corrections included) — preserved unless --force
$filled = [];
foreach ($pdo->query("SELECT state_code, ueis FROM state_uei") as $r) {
    if (trim((string) $r['ueis']) !== '') $filled[$r['state_code']] = true;
}

$seeded = 0;
$kept = 0;
$missing = [];
foreach ($STATES as $code => $names) {
    if (!$force && isset($filled[$code])) { $kept++; continue; }   // don't clobber edits
    if (isset($OVERRIDE[$code])) {
        $ueis = $OVERRIDE[$code];
    } else {
        $ueis = [];
        foreach ($names as $nm) {
            $findExact->execute([$nm]);
            foreach ($findExact->fetchAll(PDO::FETCH_COLUMN) as $u) $ueis[$u] = true;
        }
        $ueis = array_keys($ueis);
    }
    $note = count($ueis) > 1 ? 'multi-UEI: same gov' : (count($ueis) === 1 ? 'auto-seeded from name' : 'no FAC match — fill in manually');
    if (!$ueis) $missing[] = $code;
    $up->execute([':c' => $code, ':l' => $names[0], ':u' => implode("\n", $ueis), ':n' => $note]);
    $seeded++;
}

printf("Seeded %d states/territories%s.\n", $seeded, $kept ? " ($kept already filled, preserved — use --force to reseed)" : '');
if ($missing) fwrite(STDERR, "  no FAC name match for: " . implode(', ', $missing) . " (edit these in the UI)\n");
