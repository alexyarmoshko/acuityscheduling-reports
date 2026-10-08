<?php

declare(strict_types=1);

namespace AcuityReport;

use Closure;
use DateTimeImmutable;

/**
 * `--debug-tutor=<id>`: one line per season date for one calendar, each slot
 * with its status and source. Reads the snapshot store but never writes it.
 */
final class DebugTutorCommand
{
    /** @param (Closure(string): void)|null $progress */
    public static function run(
        AcuityClient $client,
        Config $config,
        string $dataDir,
        int $calendarId,
        DateTimeImmutable $now,
        ?Closure $progress = null,
    ): string {
        $store = SnapshotStore::load($dataDir);
        [$report] = (new SeasonAvailability($client, $config, $now, $progress))->build($store, $calendarId);

        return self::format($report);
    }

    public static function format(Report $report): string
    {
        $season = $report->tutors[0];
        $out = "Calendar {$season->tutor->id}: {$season->tutor->name}\n";
        $out .= 'Observable from ' . $report->bookableFrom->format('Y-m-d H:i')
            . ' until ' . $report->bookableUntil->format('Y-m-d H:i') . " ({$report->timezone})\n";

        foreach ($season->days as $date => $day) {
            $parts = [];
            foreach ($day->slots as $slot) {
                $parts[] = $slot->start->format('H:i') . '-' . $slot->end->format('H:i') . " {$slot->status} "
                    . ($slot->source === Slot::STORED ? "stored {$day->storedAt}" : 'live');
            }
            $line = $date . ' ' . (new DateTimeImmutable($date))->format('D') . '  ' . ($parts === [] ? '-' : implode(', ', $parts));
            if ($day->hoursUnknown) {
                $line .= '  [hours unknown]';
            }
            $out .= $line . "\n";
        }

        foreach ($report->warnings as $warning) {
            $out .= "Warning: {$warning}\n";
        }

        return $out;
    }
}
