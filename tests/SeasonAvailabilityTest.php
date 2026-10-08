<?php

declare(strict_types=1);

namespace AcuityReport\Tests;

use AcuityReport\AcuityClient;
use AcuityReport\Config;
use AcuityReport\Credentials;
use AcuityReport\Day;
use AcuityReport\DebugTutorCommand;
use AcuityReport\Report;
use AcuityReport\SeasonAvailability;
use AcuityReport\Slot;
use AcuityReport\SnapshotStore;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Retrieval and the cumulative saved days against a fake API (plan step 5).
 * Unless a test says otherwise the season is 2026-11..2027-03, notice is 18
 * hours, and the run starts on Saturday 2026-10-31 16:00. So bookableFrom is
 * Sunday 1 Nov 11:00: Sunday is the cutoff day, and Monday 2 Nov is fully
 * observable.
 */
final class SeasonAvailabilityTest extends TestCase
{
    private const SATURDAY_1600 = '2026-10-31 16:00';
    private const OBSERVED = '2026-10-31T16:00:00+00:00';
    // A run on Monday 2 Nov at 02:45 has bookableFrom 21:45 that day: the
    // 21:30 slot cannot be observed, the 22:00 slot can.
    private const MONDAY_0245 = '2026-11-02 02:45';
    private const MONDAY_OBSERVED = '2026-11-02T02:45:00+00:00';
    private const AS_OF = '2026-10-05T12:00:00+01:00';

    private FakeTransport $transport;
    /** @var list<array<string, mixed>> */
    private array $calendars = [['id' => 7, 'name' => 'Tutor A', 'email' => 'tutor@example.invalid']];
    /** @var list<array<string, mixed>> */
    private array $types = [['id' => 42, 'name' => 'Lesson', 'duration' => 30, 'active' => true, 'type' => 'service', 'calendarIDs' => [7]]];
    /** @var array<int, array<string, list<string>>> type ID => local date => free start times */
    private array $times = [];
    /** @var list<array<string, mixed>> */
    private array $appointments = [];

    protected function setUp(): void
    {
        $this->transport = (new FakeTransport())
            ->route('/calendars', fn (): array => $this->calendars)
            ->route('/appointment-types', fn (): array => $this->types)
            ->route('/availability/dates', fn (array $q): array => array_values(array_map(
                static fn (string $date): array => ['date' => $date],
                array_filter(array_keys($this->times[(int) $q['appointmentTypeID']] ?? []), static fn (string $d): bool => str_starts_with($d, $q['month'])),
            )))
            ->route('/availability/times', fn (array $q): array => array_map(
                static fn (string $time): array => ['time' => $time],
                $this->times[(int) $q['appointmentTypeID']][$q['date']] ?? [],
            ))
            ->route('/appointments', fn (array $q): array => array_values(array_filter(
                $this->appointments,
                static fn (array $a): bool => $a['calendarID'] === (int) $q['calendarID']
                    && substr($a['datetime'], 0, 10) >= $q['minDate'] && substr($a['datetime'], 0, 10) <= $q['maxDate'],
            )));
    }

    private function free(string $time, int $typeId = 42): void
    {
        $this->times[$typeId][substr($time, 0, 10)][] = $time;
    }

    private function book(string $datetime, int $duration = 30, int $calendarId = 7): void
    {
        $this->appointments[] = [
            'id' => count($this->appointments) + 1, 'datetime' => $datetime, 'duration' => (string) $duration,
            'appointmentTypeID' => 42, 'type' => 'Lesson', 'calendarID' => $calendarId,
            'firstName' => 'DUMMYCLIENT', 'email' => 'client@example.invalid',
        ];
    }

    private function addType(int $id, int $duration, array $calendarIds = [7], bool $active = true): void
    {
        $this->types[] = ['id' => $id, 'name' => "Type {$id}", 'duration' => $duration, 'active' => $active, 'type' => 'service', 'calendarIDs' => $calendarIds];
    }

    /** @return array{Report, array} */
    private function build(string $runStart = self::SATURDAY_1600, ?array $store = null, array $settings = []): array
    {
        return $this->availability($runStart, $settings)->build($store ?? SnapshotStore::empty());
    }

    private function availability(string $runStart, array $settings = []): SeasonAvailability
    {
        $config = Config::fromArray($settings + [
            'season_start' => '2026-11',
            'season_end' => '2027-03',
            'appointment_type_ids' => [42],
            'min_notice_hours' => 18,
            'max_days_ahead' => 287,
        ]);
        $client = new AcuityClient(new Credentials('1', 'k'), $this->transport, static fn (): float => 0.0, static function (float $s): void {
        });

        return new SeasonAvailability($client, $config, new DateTimeImmutable($runStart, new DateTimeZone('Europe/London')));
    }

    private static function day(Report $report, string $date, int $index = 0): Day
    {
        return $report->tutors[$index]->days[$date];
    }

    /** @return list<string> e.g. "21:30-22:00 booked live" */
    private static function slots(Day $day): array
    {
        return array_map(
            static fn (Slot $s): string => $s->start->format('H:i') . '-' . $s->end->format('H:i') . " {$s->status} {$s->source}",
            $day->slots,
        );
    }

    private static function entry(string $observedAt, ?bool $complete, string ...$hours): array
    {
        $entry = ['observedAt' => $observedAt, 'hours' => $hours];
        if ($complete !== null) {
            $entry['complete'] = $complete;
        }

        return $entry;
    }

    private static function store(array $days, int $calendarId = 7): array
    {
        return ['version' => 1, 'calendars' => [$calendarId => ['name' => 'Tutor A', 'days' => $days]]];
    }

    // --- Cumulative saved days ---

    public function testFullyObservableDateReplacesOrCreatesItsEntry(): void
    {
        $store = self::store([
            '2026-11-02' => self::entry(self::AS_OF, null, '20:00-20:30'),
            '2026-11-09' => self::entry(self::AS_OF, null, '22:00-22:30'),
        ]);
        $this->free('2026-11-02T21:30:00+0000');
        $this->book('2026-11-02T22:00:00+0000');

        [$report, $after] = $this->build(store: $store);

        $day = self::day($report, '2026-11-02');
        self::assertSame(['21:30-22:00 free live', '22:00-22:30 booked live'], self::slots($day));
        self::assertFalse($day->hoursUnknown);
        $days = $after['calendars'][7]['days'];
        self::assertSame(self::entry(self::OBSERVED, true, '21:30-22:00', '22:00-22:30'), $days['2026-11-02']);
        // Nothing seen on a fully observable date is a real observation.
        self::assertSame(self::entry(self::OBSERVED, true), $days['2026-11-09']);
        self::assertSame(self::entry(self::OBSERVED, true), $days['2026-11-03']);
    }

    public function testPastDayKeepsSavedHoursAddsNewBookingAndKeepsCancelledOne(): void
    {
        $this->book('2026-11-02T21:30:00+0000');
        $this->book('2026-11-02T22:00:00+0000');
        [, $store] = $this->build();

        // After the date: the 21:30 booking cancelled, an extra 20:00 booked.
        $this->appointments = [];
        $this->book('2026-11-02T20:00:00+0000');
        $this->book('2026-11-02T22:00:00+0000');
        [$report, $after] = $this->build('2026-11-10 12:00', $store);

        $day = self::day($report, '2026-11-02');
        self::assertSame(['20:00-20:30 booked live', '21:30-22:00 free stored', '22:00-22:30 booked live'], self::slots($day));
        self::assertFalse($day->hoursUnknown);
        self::assertSame(self::OBSERVED, $day->storedAt);
        self::assertSame(
            self::entry('2026-11-10T12:00:00+00:00', true, '20:00-20:30', '21:30-22:00', '22:00-22:30'),
            $after['calendars'][7]['days']['2026-11-02'],
        );
    }

    public function testSlotSeenOnTheCutoffDayIsKeptOnceTheDateIsPast(): void
    {
        $store = self::store(['2026-11-02' => self::entry(self::AS_OF, true, '21:30-22:00')]);
        $this->free('2026-11-02T22:00:00+0000'); // new, observable at the 21:45 cutoff

        [, $cutoff] = $this->build(self::MONDAY_0245, $store);
        self::assertSame(self::entry(self::MONDAY_OBSERVED, true, '21:30-22:00', '22:00-22:30'), $cutoff['calendars'][7]['days']['2026-11-02']);

        $this->times = [];
        [$report] = $this->build('2026-11-10 12:00', $cutoff);

        self::assertSame(['21:30-22:00 free stored', '22:00-22:30 free stored'], self::slots(self::day($report, '2026-11-02')));
    }

    public function testObservableSavedSlotNoLongerReturnedIsRemoved(): void
    {
        $store = self::store(['2026-11-02' => self::entry(self::AS_OF, true, '21:30-22:00', '22:00-22:30')]);

        [$report, $after] = $this->build(self::MONDAY_0245, $store);

        // 22:00 is observable and not returned: removed. 21:30 is not observable: kept.
        $day = self::day($report, '2026-11-02');
        self::assertSame(['21:30-22:00 free stored'], self::slots($day));
        self::assertSame(self::AS_OF, $day->storedAt);
        self::assertFalse($day->hoursUnknown);
        self::assertSame(self::entry(self::MONDAY_OBSERVED, true, '21:30-22:00'), $after['calendars'][7]['days']['2026-11-02']);
    }

    public function testSavedSlotStraddlingTheCutoffIsKept(): void
    {
        // Observability is per whole slot: 21:30-22:30 starts before the 21:45 cutoff.
        $store = self::store(['2026-11-02' => self::entry(self::AS_OF, true, '21:30-22:30')]);

        [$report] = $this->build(self::MONDAY_0245, $store);

        self::assertSame(['21:30-22:30 free stored'], self::slots(self::day($report, '2026-11-02')));
    }

    public function testPartialViewWithoutEntryIsSavedIncompleteUntilSeenWhole(): void
    {
        // bookableUntil = Monday 9 Nov 16:00, so 9 Nov is not whole; its booking is still read.
        $this->book('2026-11-09T22:00:00+0000');
        [$first, $store] = $this->build(settings: ['max_days_ahead' => 10]);

        self::assertTrue(self::day($first, '2026-11-09')->hoursUnknown);
        self::assertSame(self::entry(self::OBSERVED, false, '22:00-22:30'), $store['calendars'][7]['days']['2026-11-09']);
        self::assertArrayNotHasKey('2026-11-10', $store['calendars'][7]['days'], 'nothing to say: no entry');

        $this->free('2026-11-09T21:30:00+0000');
        [$second, $after] = $this->build('2026-11-01 16:00', $store);

        self::assertFalse(self::day($second, '2026-11-09')->hoursUnknown);
        self::assertSame(
            self::entry('2026-11-01T16:00:00+00:00', true, '21:30-22:00', '22:00-22:30'),
            $after['calendars'][7]['days']['2026-11-09'],
        );
    }

    public function testCutoffDayWithoutEntryIsSavedButFlagged(): void
    {
        $this->free('2026-11-02T21:30:00+0000');
        $this->free('2026-11-02T22:00:00+0000');

        [$report, $store] = $this->build(self::MONDAY_0245);

        $day = self::day($report, '2026-11-02');
        self::assertTrue($day->hoursUnknown);
        self::assertSame(['21:30-22:00 free live', '22:00-22:30 free live'], self::slots($day));
        self::assertSame(self::entry(self::MONDAY_OBSERVED, false, '21:30-22:00', '22:00-22:30'), $store['calendars'][7]['days']['2026-11-02']);
    }

    public function testBookingDoesNotRemoveALongerSavedSlot(): void
    {
        $store = self::store(['2026-11-02' => self::entry(self::AS_OF, true, '21:30-22:30')]);
        $this->book('2026-11-02T22:00:00+0000');
        [$booked, $after] = $this->build('2026-11-10 12:00', $store);

        self::assertSame(['21:30-22:30 free stored', '22:00-22:30 booked live'], self::slots(self::day($booked, '2026-11-02')));

        $this->appointments = []; // cancelled
        [$cancelled] = $this->build('2026-11-11 12:00', $after);

        self::assertSame(['21:30-22:30 free stored', '22:00-22:30 free stored'], self::slots(self::day($cancelled, '2026-11-02')));
    }

    public function testFreeSlotOverlappingABookingIsWarnedAboutAndBothKept(): void
    {
        $this->types[0]['duration'] = 60;
        $this->free('2026-11-02T21:30:00+0000'); // 21:30-22:30
        $this->book('2026-11-02T22:00:00+0000');

        [$report, $store] = $this->build();

        self::assertSame(['21:30-22:30 free live', '22:00-22:30 booked live'], self::slots(self::day($report, '2026-11-02')));
        self::assertSame(['21:30-22:30', '22:00-22:30'], $store['calendars'][7]['days']['2026-11-02']['hours']);
        self::assertCount(1, $report->warnings);
        self::assertStringContainsString('2026-11-02 21:30: a free slot overlaps a booking', $report->warnings[0]);
    }

    public function testSlotsAreNeverMerged(): void
    {
        $this->addType(43, 60);
        $this->free('2026-11-02T21:30:00+0000', 42);
        $this->free('2026-11-02T22:00:00+0000', 42);
        $this->free('2026-11-02T21:30:00+0000', 43);

        [$report, $store] = $this->build(settings: ['appointment_type_ids' => [42, 43]]);

        self::assertSame(
            ['21:30-22:00 free live', '21:30-22:30 free live', '22:00-22:30 free live'],
            self::slots(self::day($report, '2026-11-02')),
        );
        self::assertSame(['21:30-22:00', '21:30-22:30', '22:00-22:30'], $store['calendars'][7]['days']['2026-11-02']['hours']);
    }

    public function testIdenticalSlotsAreListedOnce(): void
    {
        $this->addType(43, 30);
        $this->free('2026-11-02T21:30:00+0000', 42);
        $this->free('2026-11-02T21:30:00+0000', 43);
        $this->free('2026-11-02T22:00:00+0000', 42);
        $this->book('2026-11-02T22:00:00+0000');

        [$report, $store] = $this->build(settings: ['appointment_type_ids' => [42, 43]]);

        self::assertSame(['21:30-22:00 free live', '22:00-22:30 booked live'], self::slots(self::day($report, '2026-11-02')));
        self::assertSame(['21:30-22:00', '22:00-22:30'], $store['calendars'][7]['days']['2026-11-02']['hours']);
    }

    public function testSlotCrossingMidnightIsLeftOutWithAWarning(): void
    {
        $store = self::store(['2026-11-02' => self::entry(self::AS_OF, true, '21:30-22:00', '22:00-22:30')]);
        $this->free('2026-11-02T22:00:00+0000');
        $this->book('2026-11-02T23:45:00+0000');

        [$report, $after] = $this->build(store: $store);

        self::assertSame(self::entry(self::OBSERVED, true, '22:00-22:30'), $after['calendars'][7]['days']['2026-11-02']);
        self::assertStringContainsString('2026-11-02: a slot crosses midnight', implode("\n", $report->warnings));
        self::assertSame(['22:00-22:30 free live'], self::slots(self::day($report, '2026-11-02')));
    }

    public function testSlotSpanningTheClockChangeIsLeftOutWithAWarning(): void
    {
        // 25 Oct 2026: 01:30 BST + 30 minutes is 01:00 GMT.
        $this->free('2026-10-25T00:30:00+0000');
        $this->free('2026-10-25T20:00:00+0000');

        [$report, $after] = $this->build('2026-10-20 12:00', settings: ['season_start' => '2026-10']);

        self::assertSame(['observedAt' => '2026-10-20T12:00:00+01:00', 'hours' => ['20:00-20:30'], 'complete' => true], $after['calendars'][7]['days']['2026-10-25']);
        self::assertStringContainsString('2026-10-25: a slot spans the clock change', implode("\n", $report->warnings));
        SnapshotStore::validate(json_decode(SnapshotStore::encode($after), true));
    }

    public function testDatesOutsideTheSeasonAndExcludedTutorsAreKept(): void
    {
        $this->calendars[] = ['id' => 8, 'name' => 'Tutor B'];
        $this->types[0]['calendarIDs'] = [7, 8];
        $store = self::store(['2025-12-01' => self::entry(self::AS_OF, null, '21:30-22:00')]);
        $store['calendars'][8] = ['name' => 'Tutor B', 'days' => ['2026-11-02' => self::entry(self::AS_OF, null, '21:30-22:00')]];

        [$report, $after] = $this->build('2026-11-10 12:00', $store, ['exclude_calendars' => [8]]);

        self::assertSame($store['calendars'][8], $after['calendars'][8]);
        self::assertSame($store['calendars'][7]['days']['2025-12-01'], $after['calendars'][7]['days']['2025-12-01']);
        self::assertSame([], $report->removed);
    }

    public function testLegacyEntryWithoutCompleteCountsAsComplete(): void
    {
        $store = self::store(['2026-11-02' => self::entry(self::AS_OF, null, '21:30-22:00')]);

        [$report, $after] = $this->build('2026-11-10 12:00', $store);

        self::assertFalse(self::day($report, '2026-11-02')->hoursUnknown);
        self::assertTrue($after['calendars'][7]['days']['2026-11-02']['complete']);
    }

    // --- Calendars: types, reportedAt, lastRun, include/exclude, withdrawal ---

    public function testTypesReportedAtAndLastRunAreSavedAndRefreshed(): void
    {
        $this->addType(43, 60);
        [$first, $store] = $this->build(settings: ['appointment_type_ids' => [42, 43]]);

        self::assertSame(self::OBSERVED, $store['calendars'][7]['reportedAt']);
        self::assertSame([['id' => 42, 'name' => 'Lesson', 'duration' => 30], ['id' => 43, 'name' => 'Type 43', 'duration' => 60]], $store['calendars'][7]['types']);
        self::assertSame([
            'generatedAt' => self::OBSERVED,
            'seasonStart' => '2026-11',
            'seasonEnd' => '2027-03',
            'types' => [['id' => 42, 'name' => 'Lesson', 'duration' => 30], ['id' => 43, 'name' => 'Type 43', 'duration' => 60]],
            'bookableFrom' => '2026-11-01T11:00:00+00:00',
            'bookableUntil' => '2027-08-13T16:00:00+01:00',
            'warnings' => $first->warnings,
        ], $store['lastRun']);

        $this->types[1]['calendarIDs'] = []; // type 43 no longer offered on calendar 7
        [, $after] = $this->build('2026-11-01 12:00', $store, ['appointment_type_ids' => [42, 43]]);

        self::assertSame([['id' => 42, 'name' => 'Lesson', 'duration' => 30]], $after['calendars'][7]['types']);
        self::assertSame('2026-11-01T12:00:00+00:00', $after['calendars'][7]['reportedAt']);
    }

    public function testIncludeThenExclude(): void
    {
        $this->calendars[] = ['id' => 8, 'name' => 'Tutor B'];
        $this->calendars[] = ['id' => 9, 'name' => 'Tutor C'];
        $this->calendars[] = ['id' => 11, 'name' => 'Not a tutor'];
        $this->types[0]['calendarIDs'] = [7, 8, 9];

        [$report] = $this->build(settings: ['include_calendars' => [7, 8, 10, 11], 'exclude_calendars' => [8]]);

        self::assertSame([7], array_map(static fn ($t): int => $t->tutor->id, $report->tutors));
        $warnings = implode("\n", $report->warnings);
        self::assertStringContainsString('Calendar 10 in include_calendars is not a tutor calendar', $warnings);
        self::assertStringContainsString('Calendar 11 in include_calendars is not a tutor calendar', $warnings);
    }

    public function testLimitedRunKeepsOtherCalendarsData(): void
    {
        $this->calendars[] = ['id' => 8, 'name' => 'Tutor B'];
        $this->types[0]['calendarIDs'] = [7, 8];
        [, $store] = $this->build();

        [$report, $after] = $this->build('2026-11-01 12:00', $store, ['include_calendars' => [7]]);

        self::assertSame([7], array_map(static fn ($t): int => $t->tutor->id, $report->tutors));
        self::assertSame($store['calendars'][8], $after['calendars'][8]);
        self::assertSame('2026-11-01T12:00:00+00:00', $after['calendars'][7]['reportedAt']);
    }

    public function testWithdrawnCalendarsAreRemoved(): void
    {
        $this->calendars[] = ['id' => 8, 'name' => 'No longer offers the type'];
        $this->calendars[] = ['id' => 9, 'name' => 'Excluded but still a tutor'];
        $this->types[0]['calendarIDs'] = [7, 9];
        $store = self::store([]);
        $store['calendars'][8] = ['name' => 'Tutor B', 'days' => ['2026-11-02' => self::entry(self::AS_OF, null)]];
        $store['calendars'][9] = ['name' => 'Tutor C', 'days' => []];
        $store['calendars'][99] = ['name' => 'Deleted', 'days' => []];

        [$report, $after] = $this->build(store: $store, settings: ['exclude_calendars' => [9]]);

        self::assertSame([7, 9], array_keys($after['calendars']));
        self::assertSame([['id' => 8, 'name' => 'Tutor B'], ['id' => 99, 'name' => 'Deleted']], $report->removed);
    }

    public function testExcludedCalendarIsRemovedWhenWithdrawn(): void
    {
        $store = self::store([]);
        $store['calendars'][99] = ['name' => 'Deleted', 'days' => []];

        [, $after] = $this->build(store: $store, settings: ['exclude_calendars' => [99]]);

        self::assertArrayNotHasKey(99, $after['calendars']);
    }

    public function testLimitedRunRemovesOnlyWithdrawnCalendarsInItsList(): void
    {
        $store = self::store([]);
        $store['calendars'][98] = ['name' => 'Deleted, not included', 'days' => []];
        $store['calendars'][99] = ['name' => 'Deleted, included', 'days' => []];

        [$report, $after] = $this->build(store: $store, settings: ['include_calendars' => [7, 99]]);

        self::assertArrayHasKey(98, $after['calendars']);
        self::assertArrayNotHasKey(99, $after['calendars']);
        self::assertSame([['id' => 99, 'name' => 'Deleted, included']], $report->removed);
    }

    public function testInvalidListedTypeRemovesCalendarsOfferingOnlyThatType(): void
    {
        $this->calendars[] = ['id' => 8, 'name' => 'Tutor B'];
        $this->addType(43, 30, [8], active: false);
        $store = self::store([]);
        $store['calendars'][8] = ['name' => 'Tutor B', 'days' => []];

        [$report, $after] = $this->build(store: $store, settings: ['appointment_type_ids' => [42, 43]]);

        self::assertArrayNotHasKey(8, $after['calendars']);
        self::assertStringContainsString('43 left out: not active', implode("\n", $report->warnings));
    }

    public function testLastTutorWithdrawnPublishesAnEmptyReport(): void
    {
        $this->types[0]['calendarIDs'] = [];
        $store = self::store(['2026-11-02' => self::entry(self::AS_OF, null)]);

        [$report, $after] = $this->build(store: $store);

        self::assertSame([], $report->tutors);
        self::assertSame([], $after['calendars']);
        self::assertStringContainsString('No tutor calendars to show', implode("\n", $report->warnings));
        self::assertArrayHasKey('lastRun', $after);
    }

    public function testIncludeListOfWithdrawnCalendarsOnlyPublishesAnEmptyReport(): void
    {
        $store = self::store([]);
        $store['calendars'][99] = ['name' => 'Deleted', 'days' => []];

        [$report, $after] = $this->build(store: $store, settings: ['include_calendars' => [99]]);

        self::assertSame([], $report->tutors);
        self::assertSame([7], array_keys($after['calendars']));
        self::assertStringContainsString('No tutor calendars to show', implode("\n", $report->warnings));
    }

    public function testNoValidListedTypeFails(): void
    {
        $this->types[0]['active'] = false;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('None of appointment_type_ids is a valid appointment type: nothing was written. Appointment type 42 left out: not active');

        $this->build();
    }

    /** @return iterable<string, array{mixed}> */
    public static function badCalendarIds(): iterable
    {
        yield 'missing' => [null];
        yield 'not a list' => [['a' => 7]];
        yield 'non-numeric entry' => [[7, 'x']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badCalendarIds')]
    public function testMalformedCalendarIdsStopTheRunInsteadOfRemovingCalendars(mixed $calendarIds): void
    {
        if ($calendarIds === null) {
            unset($this->types[0]['calendarIDs']);
        } else {
            $this->types[0]['calendarIDs'] = $calendarIds;
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('type 42 has no valid calendarIDs list');

        $this->build(store: self::store(['2026-11-02' => self::entry(self::AS_OF, null)]));
    }

    public function testDebugBuildNeitherRemovesNorRecordsALastRun(): void
    {
        $store = self::store([]);
        $store['calendars'][99] = ['name' => 'Deleted', 'days' => []];

        [$report, $after] = $this->availability(self::SATURDAY_1600)->build($store, 7);

        self::assertArrayHasKey(99, $after['calendars']);
        self::assertArrayNotHasKey('lastRun', $after);
        self::assertSame([], $report->removed);
    }

    // --- Observation window ---

    public function testFirstRunShowsMondayAndFlagsTheCutoffDay(): void
    {
        $this->free('2026-11-02T21:30:00+0000');
        $this->free('2026-11-02T22:00:00+0000');

        [$report] = $this->build();

        self::assertFalse(self::day($report, '2026-11-02')->hoursUnknown);
        self::assertCount(2, self::day($report, '2026-11-02')->slots);
        self::assertTrue(self::day($report, '2026-11-01')->hoursUnknown);
    }

    public function testMarginDecidesWhetherTheNextDateIsWhole(): void
    {
        // 05:00 + 18 h + 1 h = Monday 00:00 exactly.
        [$onTime, $storeOnTime] = $this->build('2026-11-01 05:00');
        [$late, $storeLate] = $this->build('2026-11-01 05:01');

        self::assertFalse(self::day($onTime, '2026-11-02')->hoursUnknown);
        self::assertTrue($storeOnTime['calendars'][7]['days']['2026-11-02']['complete']);
        self::assertTrue(self::day($late, '2026-11-02')->hoursUnknown);
        self::assertArrayNotHasKey('2026-11-02', $storeLate['calendars'][7]['days']);
    }

    public function testFarEndNeedsTheWholeDateInsideBookableUntil(): void
    {
        // With 4 days ahead, bookableUntil is run start + 3 days.
        $store = self::store(['2026-11-02' => self::entry(self::AS_OF, true, '21:30-22:00')]);

        [, $exact] = $this->build('2026-10-31 00:00', $store, ['max_days_ahead' => 4]);
        [, $short] = $this->build('2026-10-30 23:59', $store, ['max_days_ahead' => 4]);

        self::assertSame(self::entry('2026-10-31T00:00:00+00:00', true), $exact['calendars'][7]['days']['2026-11-02']);
        // Not whole: the 21:30 slot is observable (ends before 23:59) and not returned, so it goes.
        self::assertSame(self::entry('2026-10-30T23:59:00+00:00', true), $short['calendars'][7]['days']['2026-11-02']);
    }

    public function testShortClockChangeDateEndsAtItsNextLocalMidnight(): void
    {
        // bookableUntil = 2027-03-29 00:00 BST: 28 March (23 hours long) fits.
        [, $store] = $this->build('2027-03-25 00:00', settings: ['max_days_ahead' => 5]);

        self::assertTrue($store['calendars'][7]['days']['2027-03-28']['complete']);
        self::assertArrayNotHasKey('2027-03-29', $store['calendars'][7]['days']);
    }

    public function testDayAfterBookableUntilIsFlaggedAndWarned(): void
    {
        // bookableUntil = Monday 9 Nov 16:00.
        [$report] = $this->build(settings: ['max_days_ahead' => 10]);

        self::assertFalse(self::day($report, '2026-11-08')->hoursUnknown);
        self::assertTrue(self::day($report, '2026-11-09')->hoursUnknown);
        self::assertStringContainsString('season ends after the booking horizon', implode("\n", $report->warnings));
    }

    public function testClockChangeKeepsLocalSessionTimes(): void
    {
        foreach (['2027-03-22T21:30:00+0000', '2027-03-22T22:00:00+0000', '2027-03-29T21:30:00+0100', '2027-03-29T22:00:00+0100'] as $time) {
            $this->free($time);
        }

        [$report, $store] = $this->build('2027-03-01 12:00');

        foreach (['2027-03-22', '2027-03-29'] as $date) {
            self::assertSame(['21:30-22:00 free live', '22:00-22:30 free live'], self::slots(self::day($report, $date)), $date);
            self::assertSame(['21:30-22:00', '22:00-22:30'], $store['calendars'][7]['days'][$date]['hours'], $date);
        }
    }

    // --- Season and requests ---

    public function testSeasonBoundsAreInclusiveMonths(): void
    {
        [$report] = $this->build('2027-03-01 12:00', settings: ['season_start' => '2027-03', 'season_end' => '2027-03']);

        $dates = array_keys($report->tutors[0]->days);
        self::assertSame('2027-03-01', $dates[0]);
        self::assertSame('2027-03-31', end($dates));
        self::assertCount(31, $dates);
    }

    public function testSeasonAcrossTheYearEnd(): void
    {
        [$report] = $this->build('2026-11-01 12:00', settings: ['season_start' => '2026-12', 'season_end' => '2027-01']);

        $days = $report->tutors[0]->days;
        self::assertCount(62, $days);
        self::assertArrayHasKey('2026-12-31', $days);
        self::assertArrayHasKey('2027-01-01', $days);
        $months = array_map(
            static fn (string $url): string => (string) preg_replace('/.*minDate=(\d{4}-\d{2}).*/', '$1', $url),
            array_values(array_filter($this->transport->urls, static fn (string $u): bool => str_contains($u, '/appointments?'))),
        );
        self::assertSame(['2026-12', '2027-01'], $months);
    }

    public function testFreeSlotsAreNotRequestedForPastMonths(): void
    {
        $this->build('2027-01-15 12:00');

        $months = array_map(
            static fn (string $url): string => (string) preg_replace('/.*month=(\d{4}-\d{2}).*/', '$1', $url),
            array_values(array_filter($this->transport->urls, static fn (string $u): bool => str_contains($u, '/availability/dates?'))),
        );
        self::assertSame(['2027-01', '2027-02', '2027-03'], $months);
    }

    // --- Validation and failures ---

    public function testImpossibleDateInTheApiIsRejected(): void
    {
        $this->times[42]['2026-11-02'] = ['2026-11-31T21:30:00+0000'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("'time' is not a valid time");

        $this->build();
    }

    public function testFullAppointmentsPageFails(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->book('2026-11-02T21:30:00+0000');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('may be truncated');

        $this->build();
    }

    public function testInvalidTypesAreWarnedAbout(): void
    {
        $this->types[] = ['id' => 45, 'name' => 'Class', 'duration' => 60, 'active' => true, 'type' => 'class', 'calendarIDs' => [7]];

        [$report] = $this->build(settings: ['appointment_type_ids' => [42, 44, 45]]);

        self::assertSame([42], array_column($report->types, 'id'));
        $warnings = implode("\n", $report->warnings);
        self::assertStringContainsString('44 left out: not found', $warnings);
        self::assertStringContainsString('45 left out: not of type service', $warnings);
    }

    public function testApiFailureThrows(): void
    {
        $this->transport->route('/appointments', ['message' => 'x'], 400);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GET /appointments failed: HTTP 400');

        $this->build();
    }

    public function testClientAndCalendarFieldsNeverReachTheResult(): void
    {
        $this->appointments = json_decode((string) file_get_contents(__DIR__ . '/fixtures/appointments.json'), true);
        $this->types = [['id' => 101, 'name' => 'Fixture lesson', 'duration' => 30, 'active' => true, 'type' => 'service', 'calendarIDs' => [7]]];

        [$report, $store] = $this->build('2026-10-08 12:00', settings: [
            'season_start' => '2026-03', 'season_end' => '2026-03', 'appointment_type_ids' => [101],
        ]);

        self::assertCount(1, self::day($report, '2026-03-30')->slots);
        $dump = print_r($report, true) . SnapshotStore::encode($store);
        foreach (['FIXTURE-CLIENT', 'FIXTURE-NOTES', '000-FIXTURE', 'example.invalid'] as $value) {
            self::assertStringNotContainsString($value, $dump);
        }
    }

    public function testOnlyCalendarMustBeInTheReport(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not in the report');

        $this->availability(self::SATURDAY_1600)->build(SnapshotStore::empty(), 8);
    }

    public function testDebugOutputShowsStatusSourceAndFlags(): void
    {
        $store = self::store(['2026-11-02' => self::entry(self::AS_OF, true, '21:30-22:00', '22:00-22:30')]);
        $this->free('2026-11-02T22:00:00+0000');

        [$report] = $this->availability(self::MONDAY_0245)->build($store, 7);
        $out = DebugTutorCommand::format($report);

        self::assertStringContainsString('Calendar 7: Tutor A', $out);
        self::assertStringContainsString('2026-11-02 Mon  21:30-22:00 free stored ' . self::AS_OF . ", 22:00-22:30 free live\n", $out);
        self::assertStringContainsString("2026-11-01 Sun  -  [hours unknown]\n", $out);
        self::assertStringContainsString("2026-11-03 Tue  -\n", $out);
    }
}
