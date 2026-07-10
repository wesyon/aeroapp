<?php
declare(strict_types=1);

/** Tiny cURL JSON client (GET + POST) with response-header capture. */
final class Http
{
    /** @return array{0:int,1:array<string,string>,2:mixed} [status, headers, decodedBody] */
    public static function getJson(string $url, array $headers = [], int $tries = 4): array
    {
        return self::request('GET', $url, $headers, null, $tries);
    }

    /** @return array{0:int,1:array<string,string>,2:mixed}
     * $tries caps transient-failure retries (default 4). Pass a low value for high-fan-out, resumable
     * crawls (e.g. the per-award File C outlay pull) where a stalled worker costs more than a deferred item. */
    public static function postJson(string $url, array $body, array $headers = [], int $tries = 4): array
    {
        $headers[] = 'Content-Type: application/json';
        return self::request('POST', $url, $headers, json_encode($body), $tries);
    }

    private static function request(string $method, string $url, array $headers, ?string $payload, int $tries = 4): array
    {
        $tries = max(1, $tries);
        $delay = 2;
        for ($attempt = 1; ; $attempt++) {
            $respHeaders = [];
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_USERAGENT      => 'PostmanRuntime/7.39.0', // SAM/Akamai-friendly UA
                CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$respHeaders) {
                    $p = strpos($h, ':');
                    if ($p !== false) {
                        $respHeaders[strtolower(trim(substr($h, 0, $p)))] = trim(substr($h, $p + 1));
                    }
                    return strlen($h);
                },
            ];
            if ($method === 'POST') {
                $opts[CURLOPT_POST] = true;
                $opts[CURLOPT_POSTFIELDS] = $payload;
            }
            // Use a CA bundle if the PHP build has one configured; otherwise rely on default.
            $ca = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
            if ($ca && is_file($ca)) {
                $opts[CURLOPT_CAINFO] = $ca;
            }
            curl_setopt_array($ch, $opts);

            $body   = curl_exec($ch);
            $errno  = curl_errno($ch);
            $err    = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            // retry transient failures: network error, throttling (429), gateway 5xx
            $transient = ($body === false) || in_array($status, [429, 500, 502, 503, 504], true);
            if ($transient && $attempt < $tries) {
                $wait = (isset($respHeaders['retry-after']) && is_numeric($respHeaders['retry-after']))
                    ? (int) $respHeaders['retry-after'] : $delay;
                sleep(max(1, min($wait, 60)));
                $delay = min($delay * 2, 30);
                continue;
            }
            // Exception messages embed the URL, and SAM-style URLs carry api_key=...
            // as a query param; scrub it so retry prints / sync_log rows / persisted
            // nightly logs can never leak a credential.
            $safeUrl = preg_replace('/api_key=[^&\s"]+/i', 'api_key=***', $url);
            if ($body === false) {
                throw new RuntimeException("cURL error ($errno) for $safeUrl: $err");
            }
            if ($status >= 400) {
                throw new RuntimeException("HTTP $status for $safeUrl :: " . substr((string) $body, 0, 400));
            }
            $data = json_decode((string) $body, true);
            if ($data === null && trim((string) $body) !== 'null') {
                throw new RuntimeException("JSON decode failed for $safeUrl :: " . substr((string) $body, 0, 200));
            }
            return [$status, $respHeaders, $data];
        }
    }
}
