<?php

declare(strict_types=1);

namespace AcuityReport;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use RuntimeException;

/**
 * Retrieval and the cumulative saved days (plan: "Retrieval and rebuild" and
 * step 5).
 *
 * Reads each reported calendar's free slots and bookings for the season and
 * updates its saved days. A date this run sees whole is replaced. On any other
 * date, what this run can see replaces the saved slots for that part, and the
 * saved slots it cannot see are kept. Booked or not, a slot is a configured
 * slot. Withdrawn calendars are removed from the store.
 *
 * Instants are Unix timestamps and intervals are half-open [start, end).
 * Dates and clock times are local to Europe/London.
 */
final class SeasonAvailability
{
    public const TIMEZONE = 'Europe/London';
    private const MAX_APPOINTMENTS = 100;

    private readonly DateTimeZone $tz;
    private readonly DateTimeImmutable $runStart;
    private readonly int $bookableFrom;
    private readonly int $bookableUntil;
    /** @var list<string> */
    private array $warnings = [];

    /** @param (Closure(string): void)|null $progress */
    public function __construct(
        private readonly AcuityClient $client,
        private readonly Config $config,
        DateTimeImmutable $runStart,
        private readonly ?Closure $progress = null,
    ) {
        if ($config->appointmentTypeIds === []) {
            throw new RuntimeException('appointment_type_ids is empty in config.php: run --list to find the IDs');
        }
        $this->tz = new DateTimeZone(self::TIMEZONE);
        $this->runStart = $runStart->setTimezone($this->tz);
        // The margins keep the window valid for every request of the run
        // (plan: "Observation window and whole dates").
        $this->bookableFrom = $this->runStart->getTimestamp() + ($config->minNoticeHours + 1) * 3600;
        $this->bookableUntil = $this->runStart->modify('+' . ($config->maxDaysAhead - 1) . ' days')->getTimestamp();
    }

    /**
     * @param array<string, mixed> $store as returned by SnapshotStore::load()
     * @param int|null $onlyCalendar build this calendar only (--debug-tutor)
     * @return array{Report, array<string, mixed>} what this run built, and the store to publish
     */
    public function build(array $store, ?int $onlyCalendar = null): array
    {
        $this->warnings = [];
        $types = $this->validTypes();
        if ($types === []) {
            throw new RuntimeException('None of appointment_type_ids is a valid appointment type (see the warnings above): nothing was written');
        }
        [$names, $tutorTypes] = $this->calendars($types);

        // A calendar is reported when it offers a valid listed type, is in
        // include_calendars (if set), and is not excluded.
        $include = $this->config->includeCalendars;
        foreach ($include as $id) {
            if (!isset($tutorTypes[$id])) {
                $this->warnings[] = "Calendar {$id} in include_calendars is not a tutor calendar (not found, or offers none of the listed types)";
            }
        }
        $reported = [];
        foreach ($tutorTypes as $id => $typeIds) {
            if (($include === [] || in_array($id, $include, true)) && !in_array($id, $this->config->excludeCalendars, true)) {
                $reported[$id] = $typeIds;
            }
        }
        if ($onlyCalendar !== null) {
            if (!isset($reported[$onlyCalendar])) {
                throw new RuntimeException('That calendar is not in the report: it is excluded, not included, or offers none of the listed appointment types');
            }
            $reported = [$onlyCalendar => $reported[$onlyCalendar]];
        }

        // Withdrawn calendars: missing from /calendars or offering none of the
        // valid listed types. A run limited by include_calendars checks only
        // those calendars. Exclusion does not protect a withdrawn calendar.
        $removed = [];
        if ($onlyCalendar === null) {
            foreach (array_keys($store['calendars']) as $id) {
                if (!isset($tutorTypes[$id]) && ($include === [] || in_array($id, $include, true))) {
                    $removed[] = ['id' => $id, 'name' => $store['calendars'][$id]['name']];
                    unset($store['calendars'][$id]);
                }
            }
        }
        if ($reported === []) {
            $this->warnings[] = 'No tutor calendars to show (check appointment_type_ids, include_calendars and exclude_calendars)';
        }

        $dates = [];
        foreach ($this->seasonDates() as $date) {
            $dates[$date] = $this->fullyObservable($date);
        }
        if ($this->seasonEnd()->getTimestamp() > $this->bookableUntil) {
            $this->warnings[] = 'The season ends after the booking horizon (' . $this->local($this->bookableUntil)->format('Y-m-d H:i')
                . '): later days show what earlier runs saw, or are marked hours unknown';
        }
        $observedAt = $this->runStart->format(DATE_ATOM);

        $seasons = [];
        $n = 0;
        foreach ($reported as $id => $typeIds) {
            $tutor = new Tutor($id, $names[$id]);
            if ($this->progress !== null) {
                ($this->progress)(sprintf('Calendar %d/%d: %s', ++$n, count($reported), $tutor->name));
            }
            $free = $this->freeSlots($id, $typeIds, $types);
            $booked = $this->bookings($id);
            $entries = $store['calendars'][$id]['days'] ?? [];

            $days = [];
            foreach ($dates as $date => $fullyObservable) {
                [$days[$date], $entry] = $this->day($tutor->name, $date, $fullyObservable, $free[$date] ?? [], $booked[$date] ?? [], $entries[$date] ?? null, $observedAt);
                if ($entry !== null) {
                    $entries[$date] = $entry;
                }
            }
            $calendarTypes = array_map(static fn (int $t): array => self::typeSummary($types[$t]), $typeIds);
            $store['calendars'][$id] = ['name' => $tutor->name, 'reportedAt' => $observedAt, 'types' => $calendarTypes, 'days' => $entries];
            $seasons[] = new TutorSeason($tutor, $days, $calendarTypes);
        }

        $typeList = array_values(array_map(self::typeSummary(...), $types));
        if ($onlyCalendar === null) {
            $store['lastRun'] = [
                'generatedAt' => $observedAt,
                'seasonStart' => $this->config->seasonStart,
                'seasonEnd' => $this->config->seasonEnd,
                'types' => $typeList,
                'bookableFrom' => $this->local($this->bookableFrom)->format(DATE_ATOM),
                'bookableUntil' => $this->local($this->bookableUntil)->format(DATE_ATOM),
                'warnings' => $this->warnings,
            ];
        }

        $report = new Report(
            $this->config->seasonStart,
            $this->config->seasonEnd,
            $this->runStart,
            self::TIMEZONE,
            $typeList,
            $this->local($this->bookableFrom),
            $this->local($this->bookableUntil),
            $seasons,
            $this->warnings,
            $removed,
        );

        return [$report, $store];
    }

    /**
     * @param array{id: int, name: string, duration: int} $type
     * @return array{id: int, name: string, duration: int}
     */
    private static function typeSummary(array $type): array
    {
        return ['id' => $type['id'], 'name' => $type['name'], 'duration' => $type['duration']];
    }

    /** @return array<int, array{id: int, name: string, duration: int, calendarIDs: list<int>}> */
    private function validTypes(): array
    {
        $byId = [];
        foreach ($this->client->getList('/appointment-types') as $row) {
            if (is_array($row) && is_numeric($row['id'] ?? null)) {
                $byId[(int) $row['id']] = $row;
            }
        }

        $valid = [];
        foreach ($this->config->appointmentTypeIds as $id) {
            $row = $byId[$id] ?? null;
            $problem = match (true) {
                $row === null => 'not found in Acuity',
                !self::isTrue($row['active'] ?? null) => 'not active',
                ($row['type'] ?? null) !== 'service' => 'not of type service',
                !is_numeric($row['duration'] ?? null) || (int) $row['duration'] <= 0 => 'has no duration',
                default => null,
            };
            if ($problem !== null) {
                $this->warnings[] = "Appointment type {$id} left out: {$problem}";
                continue;
            }
            // Calendars are removed when they offer no valid type, so an
            // incomplete type entry must stop the run rather than remove them.
            $calendarIds = $row['calendarIDs'] ?? null;
            if (!is_array($calendarIds) || !array_is_list($calendarIds)
                || array_filter($calendarIds, static fn (mixed $c): bool => !is_numeric($c)) !== []) {
                throw new RuntimeException("Unexpected /appointment-types response: type {$id} has no valid calendarIDs list. Nothing was written.");
            }
            $valid[$id] = [
                'id' => $id,
                'name' => is_string($row['name'] ?? null) ? $row['name'] : "Type {$id}",
                'duration' => (int) $row['duration'],
                'calendarIDs' => array_map('intval', $calendarIds),
            ];
        }

        return $valid;
    }

    /**
     * All calendars' names, and the valid listed types each tutor calendar offers.
     *
     * @param array<int, array{calendarIDs: list<int>}> $types
     * @return array{array<int, string>, array<int, list<int>>}
     */
    private function calendars(array $types): array
    {
        $names = [];
        $tutorTypes = [];
        foreach ($this->client->getList('/calendars') as $row) {
            // Only id and name are read; emails and other fields are dropped here.
            if (!is_array($row) || !is_numeric($row['id'] ?? null) || !is_string($row['name'] ?? null)) {
                throw new RuntimeException('Unexpected /calendars response: missing id or name');
            }
            $id = (int) $row['id'];
            $names[$id] = $row['name'];
            foreach ($types as $typeId => $type) {
                if (in_array($id, $type['calendarIDs'], true)) {
                    $tutorTypes[$id][] = $typeId;
                }
            }
        }

        return [$names, $tutorTypes];
    }

    /**
     * Free slots of all the calendar's types, identical intervals counted once.
     *
     * @param list<int> $typeIds
     * @param array<int, array{duration: int}> $types
     * @return array<string, list<array{int, int}>> by local date
     */
    private function freeSlots(int $calendarId, array $typeIds, array $types): array
    {
        $currentMonth = $this->runStart->format('Y-m');
        $slots = [];
        foreach ($typeIds as $typeId) {
            $seconds = $types[$typeId]['duration'] * 60;
            $query = ['appointmentTypeID' => $typeId, 'calendarID' => $calendarId, 'timezone' => self::TIMEZONE];
            foreach ($this->seasonMonths() as $month) {
                if ($month < $currentMonth) {
                    continue; // past months have no free slots
                }
                foreach ($this->client->getList('/availability/dates', ['month' => $month] + $query) as $entry) {
                    $date = is_array($entry) ? ($entry['date'] ?? null) : null;
                    if (!is_string($date) || !SnapshotStore::isDate($date)) {
                        throw new RuntimeException("Unexpected /availability/dates response: 'date' is not YYYY-MM-DD");
                    }
                    foreach ($this->client->getList('/availability/times', ['date' => $date] + $query) as $time) {
                        $start = self::instant(is_array($time) ? ($time['time'] ?? null) : null, '/availability/times', 'time');
                        $end = $start + $seconds;
                        $slots[$this->local($start)->format('Y-m-d')]["{$start}-{$end}"] = [$start, $end];
                    }
                }
            }
        }

        return array_map('array_values', $slots);
    }

    /** @return array<string, list<array{int, int}>> bookings by local date */
    private function bookings(int $calendarId): array
    {
        $bookings = [];
        foreach ($this->seasonMonths() as $month) {
            $first = new DateTimeImmutable($month . '-01', $this->tz);
            $rows = $this->client->getList('/appointments', [
                'calendarID' => $calendarId,
                'minDate' => $first->format('Y-m-d'),
                'maxDate' => $first->modify('last day of this month')->format('Y-m-d'),
                'excludeForms' => 'true',
                'max' => self::MAX_APPOINTMENTS,
            ]);
            if (count($rows) >= self::MAX_APPOINTMENTS) {
                throw new RuntimeException("GET /appointments returned " . self::MAX_APPOINTMENTS
                    . " bookings for calendar {$calendarId} in {$month}, so the month may be truncated");
            }
            foreach ($rows as $row) {
                // Only these fields are read; client fields are never touched.
                if (!is_array($row) || !is_numeric($row['id'] ?? null) || !is_numeric($row['appointmentTypeID'] ?? null)) {
                    throw new RuntimeException('Unexpected /appointments response: missing id or appointmentTypeID');
                }
                $duration = $row['duration'] ?? null;
                if (!is_numeric($duration) || (int) $duration <= 0) {
                    throw new RuntimeException("Unexpected /appointments response: 'duration' is not a positive number");
                }
                $start = self::instant($row['datetime'] ?? null, '/appointments', 'datetime');
                $bookings[$this->local($start)->format('Y-m-d')][] = [$start, $start + (int) $duration * 60];
            }
        }

        return $bookings;
    }

    /**
     * The date's new saved entry and its Day (plan step 5).
     *
     * @param list<array{int, int}> $free
     * @param list<array{int, int}> $booked
     * @param array{observedAt: string, hours: list<string>, complete?: bool}|null $entry
     * @return array{Day, array{observedAt: string, hours: list<string>, complete: bool}|null} the entry is null when nothing is written
     */
    private function day(string $calendarName, string $date, bool $fullyObservable, array $free, array $booked, ?array $entry, string $observedAt): array
    {
        foreach ($free as $interval) {
            if (Intervals::overlapsAny($interval, $booked)) {
                $this->warnings[] = "{$calendarName}, {$date} " . $this->local($interval[0])->format('H:i')
                    . ': a free slot overlaps a booking';
            }
        }

        // interval key => [start, end, status, source]; bookings first, so a
        // free slot identical to a booking is listed once, as booked.
        $slots = [];
        foreach ($booked as [$start, $end]) {
            $slots["{$start}-{$end}"] = [$start, $end, Slot::BOOKED, Slot::LIVE];
        }
        foreach ($free as [$start, $end]) {
            $slots["{$start}-{$end}"] ??= [$start, $end, Slot::FREE, Slot::LIVE];
        }

        $usedStored = false;
        if ($fullyObservable) {
            $complete = true;
        } else {
            // What this run cannot observe is kept from the saved entry, even
            // under a booking: a booking does not remove a set-up slot.
            foreach ($entry['hours'] ?? [] as $hours) {
                $interval = $this->storedInterval($date, $hours);
                if (!$this->observable($interval)) {
                    $key = "{$interval[0]}-{$interval[1]}";
                    if (!isset($slots[$key])) {
                        $slots[$key] = [$interval[0], $interval[1], Slot::FREE, Slot::STORED];
                        $usedStored = true;
                    }
                }
            }
            $complete = $entry !== null && ($entry['complete'] ?? true);
        }
        usort($slots, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $hours = [];
        $shown = [];
        foreach ($slots as [$start, $end, $status, $source]) {
            $from = $this->local($start);
            $to = $this->local($end);
            if ($from->format('Y-m-d') !== $date || $to->format('Y-m-d') !== $date) {
                $this->warnings[] = "{$calendarName}, {$date}: a slot crosses midnight and is not saved with this date's hours";
                continue;
            }
            $hours[] = $from->format('H:i') . '-' . $to->format('H:i');
            $shown[] = new Slot($from, $to, $status, $source);
        }

        $write = $fullyObservable || $hours !== [] || $entry !== null;
        $newEntry = $write ? ['observedAt' => $observedAt, 'hours' => $hours, 'complete' => $complete] : null;

        return [new Day($date, !$complete, $usedStored ? $entry['observedAt'] : null, $shown), $newEntry];
    }

    /** The whole local date, 00:00 to the next 00:00, lies inside the observation window. */
    private function fullyObservable(string $date): bool
    {
        $start = new DateTimeImmutable("{$date} 00:00", $this->tz);

        // modify() keeps local midnight, so a 23- or 25-hour date ends right.
        return $this->observable([$start->getTimestamp(), $start->modify('+1 day')->getTimestamp()]);
    }

    /** @param array{int, int} $interval */
    private function observable(array $interval): bool
    {
        return $interval[0] >= $this->bookableFrom && $interval[1] <= $this->bookableUntil;
    }

    /** @return array{int, int} */
    private function storedInterval(string $date, string $hours): array
    {
        [$start, $end] = explode('-', $hours);

        return [$this->at($date, $start), $this->at($date, $end)];
    }

    private function at(string $date, string $time): int
    {
        return (new DateTimeImmutable("{$date} {$time}", $this->tz))->getTimestamp();
    }

    private function local(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($this->tz);
    }

    /** @return list<string> every local date of the season, YYYY-MM-DD */
    private function seasonDates(): array
    {
        $dates = [];
        $end = $this->seasonEnd();
        for ($day = $this->seasonStart(); $day < $end; $day = $day->modify('+1 day')) {
            $dates[] = $day->format('Y-m-d');
        }

        return $dates;
    }

    /** @return list<string> YYYY-MM */
    private function seasonMonths(): array
    {
        $months = [];
        $end = $this->seasonEnd();
        for ($month = $this->seasonStart(); $month < $end; $month = $month->modify('first day of next month')) {
            $months[] = $month->format('Y-m');
        }

        return $months;
    }

    private function seasonStart(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->config->seasonStart . '-01 00:00', $this->tz);
    }

    /** The first instant after the season. */
    private function seasonEnd(): DateTimeImmutable
    {
        return (new DateTimeImmutable($this->config->seasonEnd . '-01 00:00', $this->tz))->modify('first day of next month');
    }

    private static function instant(mixed $value, string $path, string $field): int
    {
        if (!is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:?\d{2})\z/', $value) !== 1) {
            throw new RuntimeException("Unexpected {$path} response: '{$field}' is not an ISO 8601 time with offset");
        }
        try {
            $parsed = new DateTimeImmutable($value);
        } catch (Exception) {
            $parsed = null;
        }
        // PHP rolls impossible dates over (30 Feb becomes 2 Mar): compare back.
        if ($parsed === null || $parsed->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)) {
            throw new RuntimeException("Unexpected {$path} response: '{$field}' is not a valid time");
        }

        return $parsed->getTimestamp();
    }

    private static function isTrue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
}
