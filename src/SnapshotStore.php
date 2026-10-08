<?php

declare(strict_types=1);

namespace AcuityReport;

use DateTimeImmutable;
use JsonException;
use RuntimeException;

/**
 * The snapshot store: each calendar's configured slots per date, kept
 * cumulatively in data/availability-cache.json (plan: "Snapshot store", step 5).
 *
 * Shape: {"version": 1, "lastRun": {...}, "calendars": {"<id>": {"name": "...",
 * "reportedAt": "<ISO 8601>", "types": [{"id", "name", "duration"}], "days":
 * {"YYYY-MM-DD": {"observedAt": "<ISO 8601>", "hours": ["HH:MM-HH:MM", ...],
 * "complete": true}}}}}. `lastRun` holds the page header (validateLastRun()
 * lists its keys); `reportedAt`, `types` and `complete` are optional on load.
 * `complete` is false until a run has seen the whole date. `hours` holds one
 * local interval per slot seen (free or booked), sorted by start then end,
 * without duplicates and never merged, so overlapping slots of different
 * durations stay apart. `[]` records that no hours were seen.
 */
final class SnapshotStore
{
    public const FILE = 'availability-cache.json';
    private const VERSION = 1;
    // The booking window has a one-hour margin, computed at run start.
    private const MAX_RUN_SECONDS = 50 * 60;
    private const HOURS = '/\A(?:[01]\d|2[0-3]):[0-5]\d-(?:[01]\d|2[0-3]):[0-5]\d\z/';

    /** @return array{version: int, lastRun?: array<string, mixed>, calendars: array<int, array{name: string, reportedAt?: string, types?: list<array{id: int, name: string, duration: int}>, days: array<string, array{observedAt: string, hours: list<string>, complete?: bool}>}>} */
    public static function empty(): array
    {
        return ['version' => self::VERSION, 'calendars' => []];
    }

    /**
     * Creates $dir if needed and takes an exclusive lock on $dir/.lock. Hold
     * the returned handle until publication ends; closing it releases the lock.
     *
     * @return resource
     */
    public static function lock(string $dir)
    {
        self::ensureDir($dir, 0700);
        $handle = @fopen($dir . '/.lock', 'c');
        if ($handle === false) {
            throw new RuntimeException("Cannot open the lock file in {$dir}");
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException("Another run is using {$dir}; try again when it has finished");
        }

        return $handle;
    }

    /** @return array{version: int, lastRun?: array<string, mixed>, calendars: array<int, array{name: string, reportedAt?: string, types?: list<array{id: int, name: string, duration: int}>, days: array<string, array{observedAt: string, hours: list<string>, complete?: bool}>}>} */
    public static function load(string $dir): array
    {
        $main = $dir . '/' . self::FILE;
        $bak = $main . '.bak';
        if (!is_file($main)) {
            if (file_exists($bak)) {
                throw new RuntimeException("Snapshot store {$main} is missing but {$bak} exists: restore the .bak as {$main}, then run again");
            }
            return self::empty();
        }

        $json = @file_get_contents($main);
        if ($json === false) {
            throw new RuntimeException("Cannot read snapshot store {$main}");
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            self::validate($data);
        } catch (JsonException | RuntimeException $e) {
            $reason = $e instanceof JsonException ? 'not valid JSON' : $e->getMessage();
            throw new RuntimeException("Snapshot store {$main} is invalid ({$reason}). Fix it or restore {$bak}; nothing was written.");
        }

        /** @var array{version: int, lastRun?: array<string, mixed>, calendars: array<int, array{name: string, reportedAt?: string, types?: list<array{id: int, name: string, duration: int}>, days: array<string, array{observedAt: string, hours: list<string>, complete?: bool}>}>} $data */
        return $data;
    }

    /** Checks the whole version-1 structure; throws a RuntimeException naming the first problem. */
    public static function validate(mixed $data): void
    {
        if (!is_array($data) || !self::hasKeys($data, ['calendars', 'version'], ['lastRun'])) {
            throw new RuntimeException('expected the keys version and calendars, and optionally lastRun');
        }
        if ($data['version'] !== self::VERSION) {
            throw new RuntimeException('unknown version');
        }
        if (array_key_exists('lastRun', $data)) {
            self::validateLastRun($data['lastRun']);
        }
        if (!is_array($data['calendars'])) {
            throw new RuntimeException('calendars must be an object');
        }
        foreach ($data['calendars'] as $id => $calendar) {
            if (!is_int($id) || $id <= 0) {
                throw new RuntimeException('a calendar ID is not a positive integer');
            }
            if (!is_array($calendar) || !self::hasKeys($calendar, ['days', 'name'], ['reportedAt', 'types'])
                || !is_string($calendar['name']) || !is_array($calendar['days'])) {
                throw new RuntimeException("calendar {$id}: expected name and days, and optionally reportedAt and types");
            }
            if (array_key_exists('reportedAt', $calendar) && (!is_string($calendar['reportedAt']) || !self::isTimestamp($calendar['reportedAt']))) {
                throw new RuntimeException("calendar {$id}: reportedAt is not an ISO 8601 time");
            }
            if (array_key_exists('types', $calendar)) {
                self::validateTypes($calendar['types'], "calendar {$id}");
            }
            foreach ($calendar['days'] as $date => $entry) {
                if (!is_string($date) || !self::isDate($date)) {
                    throw new RuntimeException("calendar {$id}: a date key is not YYYY-MM-DD");
                }
                $where = "calendar {$id}, {$date}";
                if (!is_array($entry) || !self::hasKeys($entry, ['hours', 'observedAt'], ['complete'])) {
                    throw new RuntimeException("{$where}: expected observedAt and hours, and optionally complete");
                }
                if (!is_string($entry['observedAt']) || !self::isTimestamp($entry['observedAt'])) {
                    throw new RuntimeException("{$where}: observedAt is not an ISO 8601 time");
                }
                if (array_key_exists('complete', $entry) && !is_bool($entry['complete'])) {
                    throw new RuntimeException("{$where}: complete must be true or false");
                }
                if (!is_array($entry['hours']) || !array_is_list($entry['hours'])) {
                    throw new RuntimeException("{$where}: hours must be a list");
                }
                $previous = '';
                foreach ($entry['hours'] as $hours) {
                    if (!is_string($hours) || preg_match(self::HOURS, $hours) !== 1) {
                        throw new RuntimeException("{$where}: an interval is not HH:MM-HH:MM");
                    }
                    [$start, $end] = explode('-', $hours);
                    if ($start >= $end) {
                        throw new RuntimeException("{$where}: an interval does not end after it starts");
                    }
                    // Fixed-width HH:MM-HH:MM strings sort by start, then end.
                    if ($hours <= $previous) {
                        throw new RuntimeException("{$where}: intervals are duplicated or out of order");
                    }
                    $previous = $hours;
                }
            }
        }
    }

    private static function validateLastRun(mixed $lastRun): void
    {
        $keys = ['bookableFrom', 'bookableUntil', 'generatedAt', 'seasonEnd', 'seasonStart', 'types', 'warnings'];
        if (!is_array($lastRun) || !self::hasKeys($lastRun, $keys)) {
            throw new RuntimeException('lastRun: expected exactly ' . implode(', ', $keys));
        }
        foreach (['generatedAt', 'bookableFrom', 'bookableUntil'] as $key) {
            if (!is_string($lastRun[$key]) || !self::isTimestamp($lastRun[$key])) {
                throw new RuntimeException("lastRun: {$key} is not an ISO 8601 time");
            }
        }
        foreach (['seasonStart', 'seasonEnd'] as $key) {
            if (!is_string($lastRun[$key]) || preg_match('/\A\d{4}-(0[1-9]|1[0-2])\z/', $lastRun[$key]) !== 1) {
                throw new RuntimeException("lastRun: {$key} is not YYYY-MM");
            }
        }
        if ($lastRun['seasonStart'] > $lastRun['seasonEnd']) {
            throw new RuntimeException('lastRun: seasonStart is after seasonEnd');
        }
        self::validateTypes($lastRun['types'], 'lastRun');
        if (!is_array($lastRun['warnings']) || !array_is_list($lastRun['warnings'])
            || array_filter($lastRun['warnings'], static fn (mixed $w): bool => !is_string($w)) !== []) {
            throw new RuntimeException('lastRun: warnings must be a list of strings');
        }
    }

    private static function validateTypes(mixed $types, string $where): void
    {
        if (!is_array($types) || !array_is_list($types)) {
            throw new RuntimeException("{$where}: types must be a list");
        }
        foreach ($types as $type) {
            if (!is_array($type) || !self::hasKeys($type, ['duration', 'id', 'name'])
                || !is_int($type['id']) || $type['id'] <= 0
                || !is_int($type['duration']) || $type['duration'] <= 0
                || !is_string($type['name'])) {
                throw new RuntimeException("{$where}: each type needs a positive integer id and duration, and a string name");
            }
        }
    }

    /**
     * Writes the report and the store (plan: "Publication"). A failure before
     * the report is renamed into place changes nothing. A failure after it
     * leaves the new report and the previous store.
     *
     * @param array{version: int, calendars: array} $store
     */
    public static function publish(
        string $reportPath,
        string $report,
        string $dir,
        array $store,
        DateTimeImmutable $runStart,
        DateTimeImmutable $now,
    ): void {
        if ($now->getTimestamp() - $runStart->getTimestamp() > self::MAX_RUN_SECONDS) {
            throw new RuntimeException('The run took more than 50 minutes, so the booking window computed at its start may no longer hold. Nothing was written; run again.');
        }

        $json = self::encode($store);
        try {
            self::validate(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        } catch (JsonException | RuntimeException $e) {
            throw new RuntimeException('Internal error: the new snapshot store is invalid (' . $e->getMessage() . '). Nothing was written.');
        }

        self::prepareReportDir($reportPath, $dir);
        $reportTemp = self::writeTemp(dirname($reportPath), basename($reportPath), $report);
        try {
            $storeTemp = self::writeTemp($dir, self::FILE, $json);
        } catch (RuntimeException $e) {
            @unlink($reportTemp);
            throw $e;
        }

        try {
            self::installReport($reportTemp, $reportPath);
        } catch (RuntimeException $e) {
            @unlink($storeTemp);
            throw $e;
        }

        $main = $dir . '/' . self::FILE;
        $failure = null;
        // The main file is copied, never moved, so it always exists.
        if (is_file($main) && !self::backup($main)) {
            $failure = "cannot copy it to {$main}.bak";
        } elseif (!@rename($storeTemp, $main)) {
            $failure = "cannot replace {$main}";
        }
        if ($failure !== null) {
            @unlink($storeTemp);
            throw new RuntimeException("The report was written to {$reportPath}, but the snapshot store was not updated ({$failure}). The previous store is unchanged; only this run's new observations are lost.");
        }
    }

    /**
     * Writes the report only, never the store (--render-only): a temp file
     * next to the report, then a rename.
     */
    public static function publishReport(string $reportPath, string $report, string $dir): void
    {
        self::prepareReportDir($reportPath, $dir);
        self::installReport(self::writeTemp(dirname($reportPath), basename($reportPath), $report), $reportPath);
    }

    /** Renames a prepared report temp file into place; on failure removes it. */
    private static function installReport(string $temp, string $reportPath): void
    {
        if (!@rename($temp, $reportPath)) {
            @unlink($temp);
            throw new RuntimeException("Cannot write the report to {$reportPath}. Nothing was changed.");
        }
    }

    /** @param array{version: int, calendars: array} $store */
    public static function encode(array $store): string
    {
        $calendars = $store['calendars'];
        ksort($calendars);
        $out = [];
        foreach ($calendars as $id => $calendar) {
            $days = $calendar['days'];
            ksort($days);
            // Objects, so that an empty map is written as {} rather than [].
            $out[(string) $id] = array_intersect_key($calendar, array_flip(['name', 'reportedAt', 'types']))
                + ['days' => (object) $days];
        }

        $file = ['version' => self::VERSION];
        if (array_key_exists('lastRun', $store)) {
            $file['lastRun'] = $store['lastRun'];
        }
        $file['calendars'] = (object) $out;

        return json_encode($file, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    public static function isDate(string $value): bool
    {
        return preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function isTimestamp(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);

        return $parsed !== false && $parsed->format(DATE_ATOM) === $value;
    }

    /**
     * @param array<mixed> $array
     * @param list<string> $keys sorted
     */
    private static function hasKeys(array $array, array $keys, array $optional = []): bool
    {
        $actual = array_keys(array_diff_key($array, array_flip($optional)));
        sort($actual);

        return $actual === $keys;
    }

    private static function prepareReportDir(string $reportPath, string $dir): void
    {
        // The temp file sits next to the report, so the rename stays on one drive.
        self::ensureDir(dirname($reportPath), 0777);
        self::ensureDir($dir, 0700);
        // A report written over the store would also end up in its .bak.
        $reportDir = realpath(dirname($reportPath));
        $storeDir = realpath($dir);
        if ($reportDir === false || $storeDir === false
            || (PHP_OS_FAMILY === 'Windows' ? strcasecmp($reportDir, $storeDir) === 0 : $reportDir === $storeDir)) {
            throw new RuntimeException("The report cannot be written into {$dir}, which holds the snapshot store. Nothing was written.");
        }
    }

    private static function ensureDir(string $dir, int $mode): void
    {
        if (!is_dir($dir) && !@mkdir($dir, $mode, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory {$dir}. Nothing was written.");
        }
    }

    /**
     * Copies $main to $main.bak through a temp file and a rename, so a failed
     * copy leaves the previous .bak whole.
     */
    private static function backup(string $main): bool
    {
        $contents = @file_get_contents($main);
        if ($contents === false) {
            return false;
        }
        try {
            $temp = self::writeTemp(dirname($main), basename($main) . '.bak', $contents);
        } catch (RuntimeException) {
            return false;
        }
        if (!@rename($temp, $main . '.bak')) {
            @unlink($temp);
            return false;
        }

        return true;
    }

    private static function writeTemp(string $dir, string $name, string $contents): string
    {
        $path = $dir . '/.' . $name . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException("Cannot create a temporary file in {$dir}. Nothing was written.");
        }
        $written = fwrite($handle, $contents);
        $closed = fclose($handle);
        if ($written !== strlen($contents) || !$closed) {
            @unlink($path);
            throw new RuntimeException("Cannot write a temporary file in {$dir}. Nothing was written.");
        }

        return $path;
    }
}
