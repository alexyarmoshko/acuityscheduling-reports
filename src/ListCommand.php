<?php

declare(strict_types=1);

namespace AcuityReport;

use RuntimeException;

/** `--list`: calendars and appointment types with the IDs needed for config.php. */
final class ListCommand
{
    public static function run(AcuityClient $client): string
    {
        $calendars = $client->getList('/calendars');
        $types = $client->getList('/appointment-types');

        $out = "Calendars\n";
        $rows = [];
        foreach ($calendars as $calendar) {
            $rows[] = [self::int($calendar, 'id'), self::string($calendar, 'name')];
        }
        $out .= self::table(['id', 'name'], $rows);

        $out .= "\nAppointment types\n";
        $rows = [];
        foreach ($types as $type) {
            $calendarIds = $type['calendarIDs'] ?? [];
            $rows[] = [
                self::int($type, 'id'),
                self::string($type, 'name'),
                self::int($type, 'duration'),
                (string) ($type['paddingBefore'] ?? ''),
                (string) ($type['paddingAfter'] ?? ''),
                self::flag($type['active'] ?? null),
                self::flag($type['private'] ?? null),
                (string) ($type['type'] ?? ''),
                is_array($calendarIds) ? implode(',', array_map('strval', $calendarIds)) : '',
            ];
        }
        $out .= self::table(
            ['id', 'name', 'duration', 'padBefore', 'padAfter', 'active', 'private', 'type', 'calendarIDs'],
            $rows,
        );

        return $out;
    }

    /** @param array<mixed> $row */
    private static function int(mixed $row, string $key): string
    {
        if (!is_array($row) || !isset($row[$key]) || !is_numeric($row[$key])) {
            throw new RuntimeException("Unexpected API response: missing numeric '{$key}'");
        }

        return (string) $row[$key];
    }

    private static function string(mixed $row, string $key): string
    {
        if (!is_array($row) || !is_string($row[$key] ?? null)) {
            throw new RuntimeException("Unexpected API response: missing '{$key}'");
        }

        // Keep console output on one line per row.
        return trim(preg_replace('/\s+/', ' ', $row[$key]) ?? '');
    }

    private static function flag(mixed $value): string
    {
        return match (true) {
            $value === true, $value === 1, $value === '1', $value === 'true' => 'yes',
            $value === null, $value === '' => '',
            default => 'no',
        };
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    private static function table(array $headers, array $rows): string
    {
        $widths = array_map('mb_strlen', $headers);
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i], mb_strlen($cell));
            }
        }

        $line = static function (array $cells) use ($widths): string {
            $padded = [];
            foreach ($cells as $i => $cell) {
                $padded[] = $cell . str_repeat(' ', $widths[$i] - mb_strlen($cell));
            }
            return rtrim(implode('  ', $padded)) . "\n";
        };

        $out = $line($headers);
        foreach ($rows as $row) {
            $out .= $line($row);
        }
        if ($rows === []) {
            $out .= "(none)\n";
        }

        return $out;
    }
}
