<?php
declare(strict_types=1);

/**
 * Federal Audit Clearinghouse PostgREST client.
 * Pages through a table for a given filter, invoking a callback per page so
 * large result sets are streamed rather than buffered in memory.
 */
final class FacClient
{
    private string $base;

    public function __construct(string $base, private string $apiKey)
    {
        $this->base = rtrim($base, '/');
    }

    /**
     * @param string   $table   FAC endpoint name, e.g. "general"
     * @param string   $filter  PostgREST filter, e.g. 'audit_year=in.(2023,2024)'
     * @param callable $onPage  fn(array $rows): void
     * @return int     total rows fetched
     *
     * KNOWN LIMITATION (split narratives): findings_text / corrective_action_plans
     * split long text across multiple rows that share the (report_id,
     * finding_ref_number) key and carry NO sequence column. sync_fac reassembles
     * them in arrival order (make_concat_upsert), so correct order depends on the
     * source returning a finding's chunks in narrative order. This query adds no
     * ORDER BY (there is no sort key that would guarantee chunk order), so the API
     * path relies on PostgREST's default row order — observed correct, but not
     * guaranteed, and chunks could in theory straddle a page boundary. The CSV
     * seed path is deterministic (file order); for an affected report pulled via
     * --since, re-seeding it from the CSV extract restores the correct order.
     */
    public function each(string $table, string $filter, callable $onPage, int $pageSize = 500, ?int $max = null): int
    {
        $offset = 0;
        $total  = 0;
        while (true) {
            $url = "{$this->base}/{$table}?{$filter}&limit={$pageSize}&offset={$offset}";
            [, , $rows] = Http::getJson($url, [
                "X-Api-Key: {$this->apiKey}",
                'Accept: application/json',
            ]);
            if (!is_array($rows) || count($rows) === 0) {
                break;
            }
            $onPage($rows);
            $n      = count($rows);
            $total += $n;
            $offset += $n;
            if ($n < $pageSize) {
                break;
            }
            if ($max !== null && $total >= $max) {
                break;
            }
        }
        return $total;
    }

    /** Authoritative row count for a filter, via PostgREST's Content-Range header. */
    public function count(string $table, string $filter): ?int
    {
        $url = "{$this->base}/{$table}?{$filter}&limit=1";
        [, $headers, ] = Http::getJson($url, [
            "X-Api-Key: {$this->apiKey}", 'Accept: application/json', 'Prefer: count=exact',
        ]);
        // Content-Range: "0-0/12345" (or "*/12345")
        $cr = $headers['content-range'] ?? '';
        return preg_match('#/(\d+)\s*$#', $cr, $m) ? (int) $m[1] : null;
    }

    /** Fetch up to $limit report_ids for the given years (used by report-scoped test syncs). */
    public function reportIds(array $years, int $limit): array
    {
        $filter = 'audit_year=in.(' . implode(',', $years) . ')&select=report_id&order=fac_accepted_date.desc';
        $url    = "{$this->base}/general?{$filter}&limit={$limit}";
        [, , $rows] = Http::getJson($url, ["X-Api-Key: {$this->apiKey}", 'Accept: application/json']);
        return array_values(array_filter(array_map(fn ($r) => $r['report_id'] ?? null, $rows ?: [])));
    }
}
