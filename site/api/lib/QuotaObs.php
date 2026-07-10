<?php
declare(strict_types=1);

/**
 * AERO — API rate-limit ground-truth recorder (api_quota_obs).
 *
 * The Data Status quota indicator used to ESTIMATE usage as rows÷records-per-request. That's a
 * guess. Some APIs tell you exactly; this records what they say so the console can show truth:
 *
 *   FAC (api.data.gov) returns X-RateLimit-Limit / X-RateLimit-Remaining on every response
 *     → fromHeaders() captures the live remaining count.
 *   SAM (api.sam.gov entity) reports nothing on a 200, only a 429 when you're over the daily cap
 *     → limitHit() records the reset time so the card can say "resets 00:00 UTC" instead of guessing.
 *
 * One row per source (upsert on the source PK). Best-effort: never throws — a monitoring write must
 * never break a sync. Takes the CURRENT $pdo so it survives the mid-run reconnects the SAM scripts do.
 */
final class QuotaObs
{
    /** Upsert the observation for a source (any subset of fields; nulls overwrite deliberately). */
    public static function record(PDO $pdo, string $source, ?int $limit, ?int $remaining,
                                  ?string $observedAt, ?string $resetAt = null, ?string $note = null): void
    {
        try {
            $pdo->prepare(
                "INSERT INTO api_quota_obs (source, limit_total, remaining, observed_at, reset_at, note)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   limit_total = VALUES(limit_total), remaining = VALUES(remaining),
                   observed_at = VALUES(observed_at), reset_at = VALUES(reset_at), note = VALUES(note)"
            )->execute([$source, $limit, $remaining, $observedAt, $resetAt, $note]);
        } catch (Throwable $e) { /* best-effort: monitoring must never break a sync */ }
    }

    /**
     * Record from a response's rate-limit headers if present (FAC/api.data.gov style).
     * Headers are lowercase-keyed (Http.php normalizes them). No-ops silently when the API
     * doesn't send them (SAM), so it's safe to call after every request.
     */
    public static function fromHeaders(PDO $pdo, string $source, array $headers): void
    {
        if (!array_key_exists('x-ratelimit-remaining', $headers)) return;
        $remaining = (int) $headers['x-ratelimit-remaining'];
        $limit     = isset($headers['x-ratelimit-limit']) ? (int) $headers['x-ratelimit-limit'] : null;
        self::record($pdo, $source, $limit, $remaining, gmdate('Y-m-d H:i:s'));
    }

    /**
     * Record a hard rate-limit hit (SAM 429). SAM's Entity API resets at midnight UTC, so when we
     * can't read a Retry-After we record the next UTC midnight — the card shows when writes resume.
     */
    public static function limitHit(PDO $pdo, string $source, ?int $limit, ?string $resetAt = null,
                                    ?string $note = 'daily limit reached'): void
    {
        // next UTC midnight, computed from the epoch so it's timezone-independent (epoch day
        // boundaries ARE UTC midnights): floor(now/86400)*86400 = today 00:00 UTC, +1 day.
        $reset = $resetAt ?? gmdate('Y-m-d H:i:s', (intdiv(time(), 86400) + 1) * 86400);
        self::record($pdo, $source, $limit, 0, gmdate('Y-m-d H:i:s'), $reset, $note);
    }
}
