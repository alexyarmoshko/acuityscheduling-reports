<?php

declare(strict_types=1);

namespace AcuityReport;

use RuntimeException;
use Throwable;

/** Validated report settings, read from config.php. */
final class Config
{
    private const KEYS = [
        'season_start', 'season_end', 'appointment_type_ids', 'min_notice_hours',
        'max_days_ahead', 'include_calendars', 'exclude_calendars',
    ];

    /**
     * @param list<int> $appointmentTypeIds
     * @param list<int> $includeCalendars empty = all calendars
     * @param list<int> $excludeCalendars
     */
    private function __construct(
        public readonly string $seasonStart,
        public readonly string $seasonEnd,
        public readonly array $appointmentTypeIds,
        public readonly int $minNoticeHours,
        public readonly int $maxDaysAhead,
        public readonly array $includeCalendars,
        public readonly array $excludeCalendars,
    ) {
    }

    public static function load(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException("Settings file not found: {$path}. Copy config.example.php to config.php and edit it.");
        }

        // `require` prints anything before "<?php" to stdout, so a mis-saved file
        // (UTF-16, or some other file copied here) would be echoed in full.
        // Check the bytes first and never include such a file.
        $contents = is_readable($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw new RuntimeException("Settings file is not readable: {$path}");
        }
        if (str_contains($contents, "\0")) {
            throw new RuntimeException("Settings file is not UTF-8 (looks like UTF-16): save {$path} as UTF-8");
        }
        if (str_starts_with($contents, "\u{FEFF}")) {
            $contents = substr($contents, 3);
        }
        if (!str_starts_with($contents, '<?php')) {
            throw new RuntimeException("Settings file must start with <?php: {$path}");
        }
        unset($contents);

        // Capture and discard any output (e.g. a UTF-8 BOM before "<?php"),
        // including buffers the file itself may open.
        $level = ob_get_level();
        ob_start();
        try {
            $values = require $path;
        } catch (Throwable $e) {
            // Error messages can quote tokens from the file (an unquoted value
            // becomes 'Undefined constant "..."'), so report the line only and
            // do not chain the original.
            throw new RuntimeException("Settings file failed to load (error on line {$e->getLine()}): {$path}");
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
        if (!is_array($values)) {
            throw new RuntimeException("Settings file must return an array: {$path}");
        }

        return self::fromArray($values);
    }

    /** @param array<mixed> $values */
    public static function fromArray(array $values): self
    {
        $unknown = array_diff(array_keys($values), self::KEYS);
        if ($unknown !== []) {
            throw new RuntimeException('Unknown setting(s): ' . implode(', ', $unknown));
        }

        $start = self::month($values, 'season_start');
        $end = self::month($values, 'season_end');
        if ($start > $end) {
            throw new RuntimeException('season_start must not be after season_end');
        }

        return new self(
            $start,
            $end,
            // May be empty so that --list can run before the IDs are known;
            // commands that fetch availability require at least one.
            self::idList($values['appointment_type_ids'] ?? null, 'appointment_type_ids'),
            self::int($values, 'min_notice_hours', 0),
            self::int($values, 'max_days_ahead', 1),
            self::idList($values['include_calendars'] ?? [], 'include_calendars'),
            self::idList($values['exclude_calendars'] ?? [], 'exclude_calendars'),
        );
    }

    /** @param array<mixed> $values */
    private static function month(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value) || preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $value) !== 1) {
            throw new RuntimeException("{$key} must be a month in YYYY-MM format");
        }

        return $value;
    }

    /** @param array<mixed> $values */
    private static function int(array $values, string $key, int $min): int
    {
        $value = $values[$key] ?? null;
        if (!is_int($value) || $value < $min) {
            throw new RuntimeException("{$key} must be an integer of at least {$min}");
        }

        return $value;
    }

    /** @return list<int> unique positive IDs, in the given order */
    private static function idList(mixed $value, string $key): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException("{$key} must be a list of IDs");
        }
        foreach ($value as $id) {
            if (!is_int($id) || $id <= 0) {
                throw new RuntimeException("{$key} must contain positive integer IDs");
            }
        }

        return array_values(array_unique($value));
    }
}
