<?php

declare(strict_types=1);

namespace AcuityReport;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use RuntimeException;

/**
 * Retrieval and rebuild (plan: "Retrieval and rebuild").
 *
 * Reads each calendar's free slots and bookings for the season, fills what the
 * API cannot show (too soon or too far ahead) from the snapshot store, and
 * returns the report data together with the updated store. Only dates this
 * run sees whole are saved.
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
     * @param array{version: int, calendars: array<int, array{name: string, days: array<string, array{observedAt: string, hours: list<string>}>}>} $store
     *        as returned by SnapshotStore::load()
     * @param int|null $onlyCalendar build this calendar only (--debug-tutor)
     * @return array{Report, array{version: int, calendars: array}} the report data and the store to publish
     */
    public function build(array $store, ?int $onlyCalendar = null): array
    {
        $this->warnings = [];
        $types = $this->validTypes();
        $tutors = $this->tutors($types);
        if ($onlyCalendar !== null) {
            $tutors = array_values(array_filter($tutors, static fn (array $t): bool => $t[0]->id === $onlyCalendar));
            if ($tutors === []) {
                throw new RuntimeException('That calendar is not in the report: it is excluded, or offers none of the listed appointment types');
            }
        }

        $dates = [];
        foreach ($this->seasonDates() as $date) {
            $dates[$date] = $this->fullyObservable($date);
        }
        if ($this->seasonEnd()->getTimestamp() > $this->bookableUntil) {
            $this->warnings[] = 'The season ends after the booking horizon (' . $this->local($this->bookableUntil)->format('Y-m-d H:i')
                . '): later days show saved hours, or are marked hours unknown';
        }
        $observedAt = $this->runStart->format(DATE_ATOM);

        $seasons = [];
        foreach ($tutors as $n => [$tutor, $typeIds]) {
            if ($this->progress !== null) {
                ($this->progress)(sprintf('Calendar %d/%d: %s', $n + 1, count($tutors), $tutor->name));
            }
            $free = $this->freeSlots($tutor->id, $typeIds, $types);
            $booked = $this->bookings($tutor->id);
            $entries = $store['calendars'][$tutor->id]['days'] ?? [];

            $days = [];
            foreach ($dates as $date => $fullyObservable) {
                $days[$date] = $this->day($tutor->name, $date, $fullyObservable, $free[$date] ?? [], $booked[$date] ?? [], $entries[$date] ?? null);
                if ($fullyObservable) {
                    [$hours, $crossesMidnight] = $this->hours($date, $free[$date] ?? [], $booked[$date] ?? []);
                    $entries[$date] = ['observedAt' => $observedAt, 'hours' => $hours];
                    if ($crossesMidnight) {
                        $this->warnings[] = "{$tutor->name}, {$date}: a slot crosses midnight and is not saved with this date's hours";
                    }
                }
            }
            $store['calendars'][$tutor->id] = ['name' => $tutor->name, 'days' => $entries];
            $seasons[] = new TutorSeason($tutor, $days);
        }

        $report = new Report(
            $this->config->seasonStart,
            $this->config->seasonEnd,
            $this->runStart,
            self::TIMEZONE,
            array_values(array_map(
                static fn (array $t): array => ['id' => $t['id'], 'name' => $t['name'], 'duration' => $t['duration']],
                $types,
            )),
            $this->local($this->bookableFrom),
            $this->local($this->bookableUntil),
            $seasons,
            $this->warnings,
        );

        return [$report, $store];
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
            $calendarIds = is_array($row['calendarIDs'] ?? null) ? $row['calendarIDs'] : [];
            $valid[$id] = [
                'id' => $id,
                'name' => is_string($row['name'] ?? null) ? $row['name'] : "Type {$id}",
                'duration' => (int) $row['duration'],
                'calendarIDs' => array_values(array_map('intval', array_filter($calendarIds, 'is_numeric'))),
            ];
        }

        return $valid;
    }

    /**
     * Calendars offering at least one valid listed type, minus exclusions.
     *
     * @param array<int, array{calendarIDs: list<int>}> $types
     * @return list<array{Tutor, list<int>}> each with its type IDs
     */
    private function tutors(array $types): array
    {
        $tutors = [];
        foreach ($this->client->getList('/calendars') as $row) {
            // Only id and name are read; emails and other fields are dropped here.
            if (!is_array($row) || !is_numeric($row['id'] ?? null) || !is_string($row['name'] ?? null)) {
                throw new RuntimeException('Unexpected /calendars response: missing id or name');
            }
            $id = (int) $row['id'];
            if (in_array($id, $this->config->excludeCalendars, true)) {
                continue;
            }
            $typeIds = [];
            foreach ($types as $typeId => $type) {
                if (in_array($id, $type['calendarIDs'], true)) {
                    $typeIds[] = $typeId;
                }
            }
            if ($typeIds !== []) {
                $tutors[] = [new Tutor($id, $row['name']), $typeIds];
            }
        }
        if ($tutors === []) {
            throw new RuntimeException('No calendar offers any of the listed appointment types (check appointment_type_ids and exclude_calendars)');
        }

        return $tutors;
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

    /** @return array<string, list<array{start: int, end: int, typeName: ?string}>> by local date */
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
                $type = $row['type'] ?? null;
                $bookings[$this->local($start)->format('Y-m-d')][] = [
                    'start' => $start,
                    'end' => $start + (int) $duration * 60,
                    'typeName' => is_string($type) && $type !== '' ? $type : null,
                ];
            }
        }

        return $bookings;
    }

    /**
     * Applies the snapshot rules to one date, in the plan's order (retrieval
     * step 5): bookings, then live free slots that overlap no booking, then
     * stored hours this run cannot observe and live data does not cover.
     *
     * @param list<array{int, int}> $free
     * @param list<array{start: int, end: int, typeName: ?string}> $booked
     * @param array{observedAt: string, hours: list<string>}|null $entry
     */
    private function day(string $calendarName, string $date, bool $fullyObservable, array $free, array $booked, ?array $entry): Day
    {
        $bookedIntervals = array_map(static fn (array $b): array => [$b['start'], $b['end']], $booked);

        // A live free slot overlapping a booking is dropped whole, never
        // clipped. It is not expected with one appointment per slot.
        $live = [];
        foreach ($free as $interval) {
            if (Intervals::overlapsAny($interval, $bookedIntervals)) {
                $this->warnings[] = "{$calendarName}, {$date} " . $this->local($interval[0])->format('H:i')
                    . ': a free slot overlaps a booking and is not shown';
                continue;
            }
            $live[] = $interval;
        }

        $kept = array_map(static fn (array $i): array => [$i[0], $i[1], Slot::LIVE], $live);
        $hoursUnknown = false;
        $storedAt = null;
        if (!$fullyObservable) {
            if ($entry === null) {
                $hoursUnknown = true;
            } else {
                // Live data has priority: a stored hour is used only where this
                // run could not look, and where no booking or surviving live
                // slot covers it. A stored hour since booked is dropped silently.
                $occupied = array_merge($bookedIntervals, $live);
                foreach ($entry['hours'] as $hours) {
                    $interval = $this->storedInterval($date, $hours);
                    if (!$this->observable($interval) && !Intervals::overlapsAny($interval, $occupied)) {
                        $kept[] = [$interval[0], $interval[1], Slot::STORED];
                        $storedAt = $entry['observedAt'];
                    }
                }
            }
        }

        $slots = [];
        foreach ($this->mergeFree($kept) as $range) {
            $slots[] = new Slot($this->local($range['start']), $this->local($range['end']), Slot::FREE, $range['starts']);
        }
        foreach ($booked as $booking) {
            $start = $this->local($booking['start']);
            $slots[] = new Slot($start, $this->local($booking['end']), Slot::BOOKED, [$start->format('H:i') => Slot::LIVE], $booking['typeName']);
        }
        usort($slots, static fn (Slot $a, Slot $b): int
            => [$a->start->getTimestamp(), $a->end->getTimestamp()] <=> [$b->start->getTimestamp(), $b->end->getTimestamp()]);

        return new Day($date, $hoursUnknown, $storedAt, $slots);
    }

    /**
     * Merges overlapping or touching free slots into ranges.
     *
     * @param list<array{int, int, string}> $slots start, end, source
     * @return list<array{start: int, end: int, starts: array<string, string>}>
     */
    private function mergeFree(array $slots): array
    {
        usort($slots, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $ranges = [];
        $last = -1;
        foreach ($slots as [$start, $end, $source]) {
            if ($last >= 0 && $start <= $ranges[$last]['end']) {
                $ranges[$last]['end'] = max($ranges[$last]['end'], $end);
            } else {
                $ranges[++$last] = ['start' => $start, 'end' => $end, 'starts' => []];
            }
            $time = $this->local($start)->format('H:i');
            // A start both returned live and stored is reported as live.
            if (($ranges[$last]['starts'][$time] ?? null) !== Slot::LIVE) {
                $ranges[$last]['starts'][$time] = $source;
            }
        }

        return $ranges;
    }

    /**
     * The date's hours for the store: one interval per free or booked slot,
     * sorted and de-duplicated, never merged (plan: "Snapshot store", Format).
     *
     * @param list<array{int, int}> $free
     * @param list<array{start: int, end: int, typeName: ?string}> $booked
     * @return array{list<string>, bool} the hours, and whether an interval
     *         crossing midnight was left out (HH:MM-HH:MM cannot hold it)
     */
    private function hours(string $date, array $free, array $booked): array
    {
        $intervals = array_merge($free, array_map(static fn (array $b): array => [$b['start'], $b['end']], $booked));

        $hours = [];
        $crossesMidnight = false;
        foreach ($intervals as [$startAt, $endAt]) {
            $start = $this->local($startAt);
            $end = $this->local($endAt);
            if ($start->format('Y-m-d') !== $date || $end->format('Y-m-d') !== $date) {
                $crossesMidnight = true;
                continue;
            }
            $hours[] = $start->format('H:i') . '-' . $end->format('H:i');
        }
        $hours = array_values(array_unique($hours));
        sort($hours);

        return [$hours, $crossesMidnight];
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
