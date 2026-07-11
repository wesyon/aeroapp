<?php
declare(strict_types=1);

/**
 * Atomic, UTF-8-safe disk cache for JSON API responses. Guarded functions (like
 * Normalize.php / Rules.php) so the file is safe to include more than once and is
 * unit-testable in isolation (api/tests/cache_test.php).
 */
if (!function_exists('cache_put')) {
    /**
     * Write $data to $file as JSON, atomically. Encodes with the same flags as the API's
     * json_out(): JSON_INVALID_UTF8_SUBSTITUTE matters because FAC/FSRS free-text can carry
     * stray bytes that make a plain json_encode() return false — file_put_contents($f, false)
     * then writes an EMPTY file that serves `null` for the whole cache TTL. Writes to a unique
     * temp file and renames (atomic on the same filesystem) so a concurrent reader never sees a
     * half-written file, and a failed encode leaves any existing good cache in place.
     * Best-effort: never throws. Returns true if the cache file was written.
     */
    function cache_put(string $file, $data): bool
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return false;                       // never poison the cache with an empty file
        }
        @mkdir(dirname($file), 0775, true);
        $tmp = $file . '.tmp.' . getmypid() . '.' . uniqid('', true);
        if (@file_put_contents($tmp, $json) === false) {
            return false;
        }
        if (!@rename($tmp, $file)) {            // atomic replace on the same filesystem
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Read a JSON cache written by cache_put(). Returns null when the file is missing,
     * unreadable, empty, or not valid JSON (a torn or pre-hardening write) so the caller
     * recomputes rather than serving a broken `null` / partial body. TTL is the caller's job.
     */
    function cache_get(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}
