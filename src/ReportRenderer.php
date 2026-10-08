<?php

declare(strict_types=1);

namespace AcuityReport;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Report → one self-contained HTML page (plan: "Report page").
 *
 * The header and footer are rendered here, HTML-escaped. Tutors and their
 * slots go into one JSON block that the page's script reads; that block is
 * JSON-encoded with <, > and & escaped, and is not HTML-escaped. Everything is
 * pre-formatted in Europe/London, so the page does no time zone conversion.
 */
final class ReportRenderer
{
    private const TEMPLATE = __DIR__ . '/../templates/report.php';

    public static function defaultFileName(DateTimeImmutable $runStart): string
    {
        return 'availability-' . $runStart->format('Y-m-d_His') . '.html';
    }

    public static function render(Report $report): string
    {
        $tz = new DateTimeZone($report->timezone);
        $generated = $report->generatedAt->setTimezone($tz);
        $seasonEnd = (new DateTimeImmutable($report->seasonEnd . '-01', $tz))->modify('first day of next month');

        $footer = [
            'Hours are rebuilt from free and booked sessions in Acuity.',
            'Bookings and free slots read in this run are current. Hours before '
                . self::shortTime($report->bookableFrom, $tz)
                . ' that were too soon to read are filled in from the last run that saw the day in full.',
        ];
        if ($seasonEnd > $report->bookableUntil) {
            $footer[] = 'Hours after ' . self::shortTime($report->bookableUntil, $tz)
                . ' that were too far ahead to read are filled in the same way.';
        }
        $footer[] = "On an 'hours unknown' day, the bookings and free slots shown are from this run, but further"
            . ' hours may be missing: part of the day was too soon or too far ahead to read, and no earlier run saw it whole.';
        $footer[] = 'Time a tutor has blocked off is not shown.';

        $view = [
            'season' => self::monthName($report->seasonStart) . ' – ' . self::monthName($report->seasonEnd),
            'types' => implode(', ', array_map(
                static fn (array $t): string => "{$t['name']} ({$t['duration']} min)",
                $report->types,
            )),
            'timezone' => $report->timezone,
            'generated' => $generated->format('j M Y H:i'),
            'footer' => $footer,
            'warnings' => $report->warnings,
            'json' => json_encode(self::data($report, $generated, $tz), JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR),
        ];

        return self::template($view);
    }

    public static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** @return array<string, mixed> what the page's script reads */
    private static function data(Report $report, DateTimeImmutable $generated, DateTimeZone $tz): array
    {
        $months = [];
        $end = (new DateTimeImmutable($report->seasonEnd . '-01', $tz))->modify('first day of next month');
        for ($month = new DateTimeImmutable($report->seasonStart . '-01', $tz); $month < $end; $month = $month->modify('first day of next month')) {
            $months[] = $month->format('Y-m');
        }

        $tutors = [];
        foreach ($report->tutors as $season) {
            $days = [];
            foreach ($season->days as $date => $day) {
                // Days with nothing to show are left out to keep the page small.
                if ($day->slots === [] && !$day->hoursUnknown) {
                    continue;
                }
                $days[$date] = [
                    'unknown' => $day->hoursUnknown,
                    'slots' => array_map(static fn (Slot $slot): array => [
                        'from' => $slot->start->format('H:i'),
                        'to' => $slot->end->format('H:i'),
                        'status' => $slot->status,
                        'sessions' => count($slot->starts),
                        'type' => $slot->typeName,
                    ], $day->slots),
                ];
            }
            // An object even when empty, so the script can index it by date.
            $tutors[] = ['id' => $season->tutor->id, 'name' => $season->tutor->name, 'days' => (object) $days];
        }
        usort($tutors, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return [
            'months' => $months,
            'today' => $generated->format('Y-m-d'),
            'tutors' => $tutors,
        ];
    }

    private static function monthName(string $month): string
    {
        return (new DateTimeImmutable($month . '-01'))->format('F Y');
    }

    private static function shortTime(DateTimeImmutable $time, DateTimeZone $tz): string
    {
        return $time->setTimezone($tz)->format('j M H:i');
    }

    /** @param array<string, mixed> $view */
    private static function template(array $view): string
    {
        $level = ob_get_level();
        ob_start();
        try {
            (static function (array $view): void {
                require self::TEMPLATE;
            })($view);

            return (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }
}
