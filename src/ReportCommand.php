<?php

declare(strict_types=1);

namespace AcuityReport;

use Closure;
use DateTimeImmutable;

/**
 * The default run: build the report and publish it with the updated snapshot
 * store (plan: "Snapshot store", one run at a time and publication).
 */
final class ReportCommand
{
    /**
     * @param string|null $out report path; null for out/<default name> under $outDir
     * @param (Closure(): DateTimeImmutable)|null $now clock read just before publication
     * @param (Closure(string): void)|null $progress
     * @return string summary for the console, including any warnings
     */
    public static function run(
        AcuityClient $client,
        Config $config,
        string $dataDir,
        string $outDir,
        ?string $out,
        DateTimeImmutable $runStart,
        ?Closure $now = null,
        ?Closure $progress = null,
    ): string {
        $path = $out ?? $outDir . '/' . ReportRenderer::defaultFileName($runStart);

        $lock = SnapshotStore::lock($dataDir);
        try {
            $store = SnapshotStore::load($dataDir);
            [$report, $newStore] = (new SeasonAvailability($client, $config, $runStart, $progress))->build($store);
            $html = ReportRenderer::render($report);
            SnapshotStore::publish($path, $html, $dataDir, $newStore, $runStart, $now !== null ? $now() : new DateTimeImmutable());
        } finally {
            fclose($lock);
        }

        $summary = '';
        foreach ($report->warnings as $warning) {
            $summary .= "Warning: {$warning}\n";
        }

        return $summary . sprintf(
            "Report written to %s (%d calendar(s), %d API request(s)).\n",
            $path,
            count($report->tutors),
            $client->requestCount(),
        );
    }
}
