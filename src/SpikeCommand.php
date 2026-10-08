<?php

declare(strict_types=1);

namespace AcuityReport;

use DateTimeImmutable;
use RuntimeException;

/**
 * `--spike`: saves sample API responses to spike/ so the response shapes can be
 * checked before retrieval is built. Availability responses are saved raw (they
 * hold only times). From /appointments only field names and a fixed set of
 * non-client fields are kept.
 */
final class SpikeCommand
{
    private const APPOINTMENT_FIELDS = [
        'id', 'datetime', 'endTime', 'duration', 'timezone', 'canceled', 'appointmentTypeID',
    ];

    public static function run(AcuityClient $client, Config $config, string $dir, DateTimeImmutable $now): string
    {
        if ($config->appointmentTypeIds === []) {
            throw new RuntimeException('appointment_type_ids is empty in config.php: run --list to find the IDs');
        }

        $types = [];
        foreach ($client->getList('/appointment-types') as $type) {
            if (is_array($type) && isset($type['id']) && in_array((int) $type['id'], $config->appointmentTypeIds, true)) {
                $types[(int) $type['id']] = $type;
            }
        }
        $missing = array_diff($config->appointmentTypeIds, array_keys($types));
        if ($missing !== []) {
            throw new RuntimeException('appointment_type_ids not found in Acuity: ' . implode(', ', $missing));
        }

        // The first calendar offering any listed type.
        $calendarId = null;
        foreach ($config->appointmentTypeIds as $id) {
            $offered = $types[$id]['calendarIDs'] ?? [];
            if (is_array($offered) && $offered !== []) {
                $calendarId = (int) reset($offered);
                break;
            }
        }
        if ($calendarId === null) {
            throw new RuntimeException('None of the listed appointment types is offered on any calendar');
        }

        $month = max($config->seasonStart, $now->format('Y-m'));
        $files = [];

        $summary = "Spike for calendar {$calendarId}, month {$month}\n";
        foreach ($config->appointmentTypeIds as $typeId) {
            $offered = array_map('intval', is_array($types[$typeId]['calendarIDs'] ?? null) ? $types[$typeId]['calendarIDs'] : []);
            $label = "type {$typeId} (private: " . self::flag($types[$typeId]['private'] ?? null) . ')';
            if (!in_array($calendarId, $offered, true)) {
                $summary .= "- {$label}: not offered on this calendar, skipped\n";
                continue;
            }

            $dates = $client->getList('/availability/dates', [
                'month' => $month,
                'appointmentTypeID' => $typeId,
                'calendarID' => $calendarId,
                'timezone' => 'Europe/London',
            ]);
            $files["availability-dates-type{$typeId}.json"] = $dates;
            $summary .= "- {$label}: " . count($dates) . " date(s) with free slots\n";

            foreach ($dates as $entry) {
                $date = is_array($entry) && is_string($entry['date'] ?? null) ? $entry['date'] : null;
                if ($date === null || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) !== 1) {
                    throw new RuntimeException('Unexpected /availability/dates entry shape');
                }
                $times = $client->getList('/availability/times', [
                    'date' => $date,
                    'appointmentTypeID' => $typeId,
                    'calendarID' => $calendarId,
                    'timezone' => 'Europe/London',
                ]);
                $files["availability-times-type{$typeId}-{$date}.json"] = $times;
                $starts = array_map(
                    static fn ($t): string => is_array($t) && is_string($t['time'] ?? null) ? $t['time'] : '?',
                    $times,
                );
                $summary .= "    {$date}: " . implode(', ', $starts) . "\n";
            }
        }

        $first = new DateTimeImmutable($month . '-01');
        $appointments = $client->getList('/appointments', [
            'calendarID' => $calendarId,
            'minDate' => $first->format('Y-m-d'),
            'maxDate' => $first->modify('last day of this month')->format('Y-m-d'),
            'excludeForms' => 'true',
            'max' => 100,
        ]);
        $fieldNames = [];
        $samples = [];
        foreach ($appointments as $row) {
            if (!is_array($row)) {
                continue;
            }
            $fieldNames += array_fill_keys(array_keys($row), true);
            $samples[] = array_intersect_key($row, array_flip(self::APPOINTMENT_FIELDS));
        }
        ksort($fieldNames);
        $files['appointments-shape.json'] = [
            'count' => count($appointments),
            'fieldNames' => array_keys($fieldNames),
            'samples' => $samples,
        ];

        // Write only after every request has succeeded, so a failed spike
        // leaves no partial capture behind.
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}");
        }
        foreach ($files as $name => $data) {
            self::save("{$dir}/{$name}", $data);
        }
        $summary .= '- /appointments: ' . count($appointments) . " booking(s) in {$month} (field names and non-client fields saved)\n";
        $summary .= "Saved to {$dir}\n";

        return $summary;
    }

    private static function flag(mixed $value): string
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' ? 'yes' : 'no';
    }

    /** @param array<mixed> $data */
    private static function save(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json . "\n") === false) {
            throw new RuntimeException("Cannot write {$path}");
        }
    }
}
