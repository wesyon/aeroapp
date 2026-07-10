<?php
declare(strict_types=1);

/**
 * AERO — sync run lifecycle logging (sync_log).
 *
 * The old pattern wrote a sync_log row only on CLEAN COMPLETION, so a job that was
 * SIGKILLed mid-run (the shared host reaps the nightly tail at ~25-30 min; `timeout`
 * also kills a runaway) left no trace at all — it silently read as "fine" on the Data
 * Status console. This records the full lifecycle instead:
 *
 *   start()    -> inserts a 'running' row (started_at set, finished_at NULL)
 *   progress() -> updates rows_upserted + a "last progress <UTC>" note as it goes
 *   finish()   -> finalizes the SAME row to 'ok' / 'error' (finished_at set)
 *
 * A reaped/timed-out run never reaches finish(), so its row stays status='running'
 * with finished_at NULL and the last progress count — which the Data Status console
 * reports as INTERRUPTED (how far it got, when it was cut off). Nothing is silent.
 *
 * All methods are best-effort (never throw) and take the CURRENT $pdo at call time, so
 * they survive the mid-run reconnects the quota-walled SAM scripts do (the row id lives
 * in the DB, reachable from any connection).
 */
final class RunLog
{
    /** Begin a run: returns the sync_log row id (or null if the insert failed). */
    public static function start(PDO $pdo, string $source, ?string $scope, ?string $table): ?int
    {
        try {
            $pdo->prepare(
                "INSERT INTO sync_log (source, scope, table_name, rows_upserted, status, started_at, progress_at)
                 VALUES (?, ?, ?, 0, 'running', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
            )->execute([$source, $scope, $table]);
            return (int) $pdo->lastInsertId();
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Heartbeat mid-run: keep the row's count fresh and bump progress_at. A live run pushes
     *  progress_at forward every tick, so the Data Status console flags a RUNNING row as
     *  interrupted the moment progress stops (~12 min) instead of waiting out a fixed age.
     *  $requests = actual API calls issued so far (SAM has no usage header, so we count our own
     *  requests for the quota indicator); null leaves the column untouched. */
    public static function progress(PDO $pdo, ?int $id, int $rows, ?string $note = null, ?int $requests = null): void
    {
        if ($id === null) return;
        $msg = ($note !== null && $note !== '' ? $note . ' — ' : '') . 'last progress ' . gmdate('Y-m-d H:i:s') . ' UTC';
        try {
            if ($requests === null) {
                $pdo->prepare("UPDATE sync_log SET rows_upserted = ?, message = ?, progress_at = UTC_TIMESTAMP() WHERE id = ?")
                    ->execute([$rows, $msg, $id]);
            } else {
                $pdo->prepare("UPDATE sync_log SET rows_upserted = ?, requests = ?, message = ?, progress_at = UTC_TIMESTAMP() WHERE id = ?")
                    ->execute([$rows, $requests, $msg, $id]);
            }
        } catch (Throwable $e) { /* best-effort */ }
    }

    /** Push the progress deadline into the future by $seconds — call before a KNOWN pause (e.g. a
     *  quota-wall nap) so a legitimately-sleeping run isn't mistaken for a stalled/reaped one. */
    public static function defer(PDO $pdo, ?int $id, int $seconds, ?string $note = null): void
    {
        if ($id === null) return;
        try {
            $pdo->prepare("UPDATE sync_log SET progress_at = UTC_TIMESTAMP() + INTERVAL ? SECOND, message = ? WHERE id = ?")
                ->execute([max(0, $seconds), $note, $id]);
        } catch (Throwable $e) { /* best-effort */ }
    }

    /** Finalize the run. If start() failed (no id), inserts a terminal row so the outcome
     *  is still recorded. status is typically 'ok' or 'error'. $requests = total API calls this
     *  run made (recorded for the quota indicator; null leaves the column untouched). */
    public static function finish(PDO $pdo, ?int $id, string $source, ?string $scope, ?string $table,
                                  string $status, int $rows, ?string $msg = null, ?int $requests = null): void
    {
        try {
            if ($id === null) {
                $pdo->prepare(
                    "INSERT INTO sync_log (source, scope, table_name, rows_upserted, requests, status, message, started_at, finished_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
                )->execute([$source, $scope, $table, $rows, $requests, $status, $msg]);
            } else {
                $pdo->prepare(
                    "UPDATE sync_log SET status = ?, rows_upserted = ?, requests = COALESCE(?, requests), message = ?, finished_at = UTC_TIMESTAMP()
                     WHERE id = ?"
                )->execute([$status, $rows, $requests, $msg, $id]);
            }
        } catch (Throwable $e) { /* best-effort */ }
    }
}
