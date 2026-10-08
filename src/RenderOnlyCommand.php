<?php

declare(strict_types=1);

namespace AcuityReport;

use DateTimeImmutable;

/**
 * `--render-only`: the page from the saved store alone (plan step 5). No API
 * calls and no credentials. It takes the run lock, so it cannot publish over a
 * concurrent run's report, and it never writes the store.
 */
final class RenderOnlyCommand
{
    /** @param string|null $out report path; null for <default name> under $outDir */
    public static function run(Config $config, string $dataDir, string $outDir, ?string $out, DateTimeImmutable $now): string
    {
        $path = $out ?? $outDir . '/' . ReportRenderer::defaultFileName($now);

        $lock = SnapshotStore::lock($dataDir);
        try {
            $html = ReportRenderer::render(SnapshotStore::load($dataDir), $config->includeCalendars, $config->excludeCalendars);
            SnapshotStore::publishReport($path, $html, $dataDir);
        } finally {
            fclose($lock);
        }

        return "Report written to {$path} (from saved data).\n";
    }
}
