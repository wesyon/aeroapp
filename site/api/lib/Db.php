<?php
declare(strict_types=1);

/** PDO factory + small helpers shared by sync and API code. */
final class Db
{
    public static function connect(): PDO
    {
        $dsn  = Env::require('DB_DSN');
        $user = Env::get('DB_USER', 'root');
        $pass = Env::get('DB_PASSWORD', '');
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Long HTTP stalls in the CLI syncs (a slow federal endpoint retrying for
            // minutes) can outlive shared hosting's short wait_timeout and kill the
            // connection mid-run ("MySQL server has gone away"); keep the session
            // alive for 8h. Harmless for short-lived web requests.
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION wait_timeout = 28800',
        ];
        // Managed MySQL (Azure Database for MySQL, AWS RDS) rejects plaintext
        // connections by default (require_secure_transport=ON), and PDO only
        // negotiates TLS when a CA is configured. Point DB_SSL_CA at the
        // provider's CA bundle to enable; leave unset where the server doesn't
        // require TLS (local dev, current Hostinger socket).
        $ca = Env::get('DB_SSL_CA');
        if ($ca !== null && $ca !== '') {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
        }
        // Persistent connections: reuse the worker's connection across requests
        // instead of paying connect+TLS per request — matters on managed cloud
        // DBs (a network hop away). Opt-in via env: it must stay OFF on shared
        // hosting, where held connections count against per-user caps.
        if (in_array(strtolower((string) Env::get('DB_PERSISTENT', '')), ['1', 'true', 'yes', 'on'], true)) {
            $options[PDO::ATTR_PERSISTENT] = true;
        }
        return new PDO($dsn, $user, $pass, $options);
    }
}

/**
 * Caches one prepared INSERT ... ON DUPLICATE KEY UPDATE per table so a bulk
 * sync doesn't re-prepare identical SQL for every row.
 */
final class Upserter
{
    private array $stmts = [];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Upsert a row. Columns named in $orCols accumulate the MAX across colliding
     * rows (a NULL-safe boolean OR) instead of last-write-wins — needed when a
     * single logical record is delivered as several physical rows sharing the PK,
     * with a flag split across them.
     *
     * FAC's findings feed is grained per (finding x award): a finding that spans
     * multiple federal awards arrives as one row per award, and a Y/N flag
     * (modified opinion, material weakness, ...) is Y only on the award it applies
     * to and N on the co-listed ones. Plain VALUES() overwrite keeps whichever
     * award row happens to land last, silently dropping the Y; the OR keeps it.
     */
    public function insert(string $table, array $row, array $orCols = []): void
    {
        $cols = array_keys($row);
        $key  = $table . '|' . implode(',', $cols) . '|' . implode(',', $orCols);
        if (!isset($this->stmts[$key])) {
            $or  = array_flip($orCols);
            $ph  = implode(',', array_map(fn ($c) => ":$c", $cols));
            $upd = implode(',', array_map(
                fn ($c) => isset($or[$c])
                    // NULL-safe max: a -1 sentinel stands in for NULL so a missing
                    // value never beats an existing 0/1, and a collision that is
                    // NULL on every row stays NULL.
                    ? "`$c`=NULLIF(GREATEST(COALESCE(`$c`,-1),COALESCE(VALUES(`$c`),-1)),-1)"
                    : "`$c`=VALUES(`$c`)",
                $cols));
            $sql = "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES ($ph) "
                 . "ON DUPLICATE KEY UPDATE $upd";
            $this->stmts[$key] = $this->pdo->prepare($sql);
        }
        $this->stmts[$key]->execute($row);
    }
}
