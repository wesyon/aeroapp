<?php
declare(strict_types=1);

/**
 * Seeds FAC data from the official FAC bulk CSV extracts (one file per endpoint,
 * columns named identically to the API fields, every file carrying audit_year).
 *
 * Streamed with fgetcsv so multi-GB files (federal_awards ~1.3GB) never load into
 * memory, and quoted free-text (finding_text, notes) with embedded commas/newlines
 * parses correctly. Covers 10 of 11 FAC tables (no resubmission.csv).
 */
final class CsvSource
{
    private string $dir;
    private array $years;

    private const FILES = [
        'general', 'findings', 'federal_awards', 'findings_text', 'corrective_action_plans',
        'passthrough', 'notes_to_sefa', 'additional_ueis', 'additional_eins', 'secondary_auditors',
    ];

    public function __construct(string $dir, array $years)
    {
        $this->dir   = rtrim($dir, "/\\");
        $this->years = array_map('intval', $years);
    }

    private function path(string $endpoint): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . $endpoint . '.csv';
    }

    public function has(string $endpoint): bool
    {
        return in_array($endpoint, self::FILES, true) && is_file($this->path($endpoint));
    }

    /** @param callable $onPage fn(array $rows): void — rows are assoc (header => value). */
    public function fetch(string $endpoint, callable $onPage, int $batch = 2000): void
    {
        if (!$this->has($endpoint)) {
            return;
        }
        $fh = fopen($this->path($endpoint), 'r');
        if ($fh === false) {
            return;
        }
        $header = fgetcsv($fh);
        if ($header === false) {
            fclose($fh);
            return;
        }
        $yearIdx = array_search('audit_year', $header, true);
        $page = [];
        while (($row = fgetcsv($fh)) !== false) {
            if ($yearIdx !== false && !in_array((int) ($row[$yearIdx] ?? 0), $this->years, true)) {
                continue;
            }
            $assoc = [];
            foreach ($header as $i => $col) {
                $assoc[$col] = $row[$i] ?? null;
            }
            $page[] = $assoc;
            if (count($page) >= $batch) {
                $onPage($page);
                $page = [];
            }
        }
        if ($page) {
            $onPage($page);
        }
        fclose($fh);
    }
}
