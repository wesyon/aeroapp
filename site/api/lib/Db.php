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
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            // Long HTTP stalls in the CLI syncs (a slow federal endpoint retrying for
            // minutes) can outlive shared hosting's short wait_timeout and kill the
            // connection mid-run ("MySQL server has gone away"); keep the session
            // alive for 8h. Harmless for short-lived web requests.
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION wait_timeout = 28800',
        ]);
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

    public function insert(string $table, array $row): void
    {
        $cols = array_keys($row);
        $key  = $table . '|' . implode(',', $cols);
        if (!isset($this->stmts[$key])) {
            $ph  = implode(',', array_map(fn ($c) => ":$c", $cols));
            $upd = implode(',', array_map(fn ($c) => "`$c`=VALUES(`$c`)", $cols));
            $sql = "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES ($ph) "
                 . "ON DUPLICATE KEY UPDATE $upd";
            $this->stmts[$key] = $this->pdo->prepare($sql);
        }
        $this->stmts[$key]->execute($row);
    }
}
