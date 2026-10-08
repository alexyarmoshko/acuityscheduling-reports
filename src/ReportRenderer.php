<?php

declare(strict_types=1);

namespace AcuityReport;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Saved store → one self-contained HTML page (plan: "Report page" and step 5).
 *
 * A report run and --render-only both render from the store, so they give the
 * same page from the same file. The header and footer come from `lastRun` and
 * are rendered here, HTML-escaped. Calendars and their configured slots go into
 * one JSON block that the page's script reads; that block is JSON-encoded with
 * <, > and & escaped, and is not HTML-escaped. Everything is pre-formatted in
 * Europe/London, so the page does no time zone conversion.
 */
final class ReportRenderer
{
    private const TEMPLATE = __DIR__ . '/../templates/report.php';

    public static function defaultFileName(DateTimeImmutable $runStart): string
    {
        return 'availability-' . $runStart->format('Y-m-d_His') . '.html';
    }

    /**
     * @param array<string, mixed> $store as loaded, or as built by a report run
     * @param list<int> $include include_calendars (empty = all)
     * @param list<int> $exclude exclude_calendars
     */
    public static function render(array $store, array $include = [], array $exclude = []): string
    {
        $run = $store['lastRun'] ?? null;
        if (!is_array($run)) {
            throw new RuntimeException('No saved report yet: run once without --render-only');
        }
        $tz = new DateTimeZone(SeasonAvailability::TIMEZONE);
        $generated = (new DateTimeImmutable($run['generatedAt']))->setTimezone($tz);
        $from = (new DateTimeImmutable($run['bookableFrom']))->setTimezone($tz);
        $until = (new DateTimeImmutable($run['bookableUntil']))->setTimezone($tz);
        $seasonEnd = (new DateTimeImmutable($run['seasonEnd'] . '-01', $tz))->modify('first day of next month');

        $readable = 'Each day shows what the latest run could read, plus what earlier runs saw for the parts it could not: before '
            . $from->format('j M H:i') . ' (too soon)';
        if ($seasonEnd > $until) {
            $readable .= ' and after ' . $until->format('j M H:i') . ' (too far ahead)';
        }
        $footer = [
            'Shown are the sessions set up in Acuity, booked or not.',
            $readable . '.',
            "'Hours unknown': no run has yet seen this day in full, so further hours may be missing.",
            'Time a tutor has blocked off is not shown.',
        ];

        $view = [
            'season' => self::monthName($run['seasonStart'], $tz) . ' – ' . self::monthName($run['seasonEnd'], $tz),
            'timezone' => SeasonAvailability::TIMEZONE,
            'generated' => $generated->format('j M Y H:i'),
            'footer' => $footer,
            'warnings' => $run['warnings'],
            'json' => json_encode(
                self::data($store, $run, $generated, $tz, $include, $exclude),
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR,
            ),
        ];

        return self::template($view);
    }

    public static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * What the page's script reads.
     *
     * @param array<string, mixed> $store
     * @param array<string, mixed> $run
     * @param list<int> $include
     * @param list<int> $exclude
     * @return array<string, mixed>
     */
    private static function data(array $store, array $run, DateTimeImmutable $generated, DateTimeZone $tz, array $include, array $exclude): array
    {
        $months = [];
        $dates = [];
        $end = (new DateTimeImmutable($run['seasonEnd'] . '-01', $tz))->modify('first day of next month');
        for ($month = new DateTimeImmutable($run['seasonStart'] . '-01', $tz); $month < $end; $month = $month->modify('first day of next month')) {
            $months[] = $month->format('Y-m');
        }
        for ($day = new DateTimeImmutable($run['seasonStart'] . '-01', $tz); $day < $end; $day = $day->modify('+1 day')) {
            $dates[] = $day->format('Y-m-d');
        }

        $tutors = [];
        foreach ($store['calendars'] as $id => $calendar) {
            if (($include !== [] && !in_array($id, $include, true)) || in_array($id, $exclude, true)) {
                continue;
            }
            $days = [];
            foreach ($dates as $date) {
                $entry = $calendar['days'][$date] ?? null;
                // No entry, or one no run has seen whole: further hours may be missing.
                $unknown = $entry === null || !($entry['complete'] ?? true);
                $slots = array_map(
                    static fn (string $hours): string => str_replace('-', '–', $hours),
                    $entry['hours'] ?? [],
                );
                // Days with nothing to show are left out to keep the page small.
                if ($slots !== [] || $unknown) {
                    $days[$date] = ['unknown' => $unknown, 'slots' => $slots];
                }
            }

            $reportedAt = $calendar['reportedAt'] ?? null;
            $asOf = match (true) {
                $reportedAt === null => 'an earlier run',
                new DateTimeImmutable($reportedAt) < $generated => (new DateTimeImmutable($reportedAt))->setTimezone($tz)->format('j M Y H:i'),
                default => null,
            };

            $tutors[] = [
                'id' => $id,
                'name' => $calendar['name'],
                'asOf' => $asOf,
                'types' => implode(', ', array_map(
                    static fn (array $t): string => "{$t['name']} ({$t['duration']} min)",
                    $calendar['types'] ?? [],
                )),
                // An object even when empty, so the script can index it by date.
                'days' => (object) $days,
            ];
        }
        usort($tutors, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return [
            'months' => $months,
            'today' => $generated->format('Y-m-d'),
            'tutors' => $tutors,
        ];
    }

    private static function monthName(string $month, DateTimeZone $tz): string
    {
        return (new DateTimeImmutable($month . '-01', $tz))->format('F Y');
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
