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
 * Retrieval and rebuild against a fake API (plan revision 7). Unless a test
 * says otherwise the season is 2026-11..2027-03, notice is 18 hours, and the
 * run starts on Saturday 2026-10-31 16:00. So bookableFrom is Sunday 1 Nov
 * 11:00: Sunday is the cutoff day, and Monday 2 Nov is fully observable.
 */
final class SeasonAvailabilityTest extends TestCase
{
    private const SATURDAY_1600 = '2026-10-31 16:00';
    private const OBSERVED = '2026-10-31T16:00:00+00:00';
    // A run on Monday 2 Nov at 02:45 has bookableFrom 21:45 that day: the
    // 21:30 slot cannot be observed, the 22:00 slot can.
    private const MONDAY_0245 = '2026-11-02 02:45';
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

    private function addType(int $id, int $duration): void
    {
        $this->types[] = ['id' => $id, 'name' => "Type {$id}", 'duration' => $duration, 'active' => true, 'type' => 'service', 'calendarIDs' => [7]];
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

    private static function day(Report $report, string $date): Day
    {
        return $report->tutors[0]->days[$date];
    }

    /** @return list<string> e.g. "21:30-22:30 free 21:30 live, 22:00 stored" */
    private static function slots(Day $day): array
    {
        return array_map(static function (Slot $slot): string {
            $starts = [];
            foreach ($slot->starts as $time => $source) {
                $starts[] = "{$time} {$source}";
            }
            return $slot->start->format('H:i') . '-' . $slot->end->format('H:i') . " {$slot->status} " . implode(', ', $starts);
        }, $day->slots);
    }

    private static function entry(string $observedAt, string ...$hours): array
    {
        return ['observedAt' => $observedAt, 'hours' => $hours];
    }

    private static function store(array $days, int $calendarId = 7): array
    {
        return ['version' => 1, 'calendars' => [$calendarId => ['name' => 'Tutor A', 'days' => $days]]];
    }

    // --- Snapshot rules ---

    public function testFullyObservableDateReplacesOrCreatesItsEntry(): void
    {
        $store = self::store([
            '2026-11-02' => self::entry(self::AS_OF, '20:00-20:30'),
            '2026-11-09' => self::entry(self::AS_OF, '22:00-22:30'),
        ]);
        $this->free('2026-11-02T21:30:00+0000');
        $this->book('2026-11-02T22:00:00+0000');

        [$report, $after] = $this->build(store: $store);

        $day = self::day($report, '2026-11-02');
        self::assertSame(['21:30-22:00 free 21:30 live', '22:00-22:30 booked 22:00 live'], self::slots($day));
        self::assertFalse($day->hoursUnknown);
        self::assertNull($day->storedAt);
        self::assertSame('Lesson', $day->slots[1]->typeName);
        $days = $after['calendars'][7]['days'];
        self::assertSame(self::entry(self::OBSERVED, '21:30-22:00', '22:00-22:30'), $days['2026-11-02']);
        // Nothing seen on a fully observable date is a real observation.
        self::assertSame(self::entry(self::OBSERVED), $days['2026-11-09']);
        self::assertSame(self::entry(self::OBSERVED), $days['2026-11-03']);
        self::assertSame('Tutor A', $after['calendars'][7]['name']);
    }

    public function testBookedHoursAreKeptAndShowFreeAfterCancellation(): void
    {
        $this->book('2026-11-02T21:30:00+0000');
        $this->book('2026-11-02T22:00:00+0000');
        [, $store] = $this->build();
        self::assertSame(['21:30-22:00', '22:00-22:30'], $store['calendars'][7]['days']['2026-11-02']['hours']);

        // Both bookings cancelled after the date has passed.
        $this->appointments = [];
        [$report, $after] = $this->build('2026-11-10 12:00', $store);

        $day = self::day($report, '2026-11-02');
        self::assertSame(['21:30-22:30 free 21:30 stored, 22:00 stored'], self::slots($day));
        self::assertSame(self::OBSERVED, $day->storedAt);
        self::assertFalse($day->hoursUnknown);
        self::assertSame($store['calendars'][7]['days']['2026-11-02'], $after['calendars'][7]['days']['2026-11-02']);
    }

    public function testCutoffDayShowsOnlyStoredHoursThisRunCannotObserve(): void
    {
        // 22:00 is observable at a 21:45 cutoff and the API no longer returns
        // it, so its stored hour is not shown.
        $store = self::store(['2026-11-02' => self::entry(self::AS_OF, '21:30-22:00', '22:00-22:30')]);

        [$report, $after] = $this->build(self::MONDAY_0245, $store);

        $day = self::day($report, '2026-11-02');
        self::assertSame(['21:30-22:00 free 21:30 stored'], self::slots($day));
        self::assertSame(self::AS_OF, $day->storedAt);
        self::assertFalse($day->hoursUnknown);
        self::assertSame($store['calendars'][7]['days']['2026-11-02'], $after['calendars'][7]['days']['2026-11-02']);
    }

    public function testStoredSlotStraddlingTheCutoffIsShown(): void
    {
        // Observability is per whole slot: 21:30-22:30 starts before the 21:45
        // cutoff, so it is not observable even though most of it is.
        $store = self::store(['2026-11-02' => self::entry(self::AS_OF, '21:30-22:30')]);

        [$report] = $this->build(self::MONDAY_0245, $store);

        self::assertSame(['21:30-22:30 free 21:30 stored'], self::slots(self::day($report, '2026-11-02')));
    }

    public function testLiveSlotReplacesTheStoredIntervalItOverlaps(): void
    {
        $store = self::store(['2026-11-02' => self::entry(self::AS_OF, '21:30-22:00', '22:00-22:30')]);
        $this->free('2026-11-02T21:30:00+0000'); // still returned inside the margin
        $this->free('2026-11-02T22:00:00+0000');

        [$report] = $this->build(self::MONDAY_0245, $store);

        $day = self::day($report, '2026-11-02');
        self::assertSame(['21:30-22:30 free 21:30 live, 22:00 live'], self::slots($day));
        self::assertNull($day->storedAt);
    }

    public function testLiveSlotDroppedForABookingDoesNotHideAStoredSession(): void
    {
        $this->types[0]['duration'] = 60;
        $store = self::store(['2026-11-02' => self::entry(self::AS_OF, '21:30-22:00')]);
        $this->free('2026-11-02T21:30:00+0000'); // 21:30-22:30, overlaps the booking
        $this->book('2026-11-02T22:00:00+0000');

        [$report] = $this->build(self::MONDAY_0245, $store);

        self::assertSame(
            ['21:30-22:00 free 21:30 stored', '22:00-22:30 booked 22:00 live'],
            self::slots(self::day($report, '2026-11-02')),
        );
        self::assertCount(1, $report->warnings);
    }

    public function testStoredHourNowBookedShowsOnlyTheBookingWithoutWarning(): void
    {
        $store = self::store(['2026-11-02' => self::entry(self::AS_OF, '21:30-22:00', '22:00-22:30')]);
        $this->book('2026-11-02T21:30:00+0000');

        [$report] = $this->build('2026-11-10 12:00', $store);

        self::assertSame(
            ['21:30-22:00 booked 21:30 live', '22:00-22:30 free 22:00 stored'],
            self::slots(self::day($report, '2026-11-02')),
        );
        self::assertSame([], $report->warnings);
    }

    public function testLiveFreeSlotOverlappingABookingIsDroppedWholeWithWarning(): void
    {
        $this->types[0]['duration'] = 60;
        $this->free('2026-11-02T20:30:00+0000');
        $this->free('2026-11-02T21:30:00+0000'); // 21:30-22:30, overlaps the booking
        $this->book('2026-11-02T22:00:00+0000');

        [$report] = $this->build();

        self::assertSame(
            ['20:30-21:30 free 20:30 live', '22:00-22:30 booked 22:00 live'],
            self::slots(self::day($report, '2026-11-02')),
        );
        self::assertCount(1, $report->warnings);
        self::assertStringContainsString('2026-11-02 21:30', $report->warnings[0]);
    }

    public function testDateNotSeenWholeWithoutEntryIsHoursUnknownAndNotStored(): void
    {
        $this->book('2026-11-02T22:00:00+0000');

        [$report, $store] = $this->build('2026-11-02 12:00');

        $day = self::day($report, '2026-11-02');
        self::assertTrue($day->hoursUnknown);
        self::assertSame(['22:00-22:30 booked 22:00 live'], self::slots($day));
        self::assertArrayNotHasKey('2026-11-02', $store['calendars'][7]['days']);
        // bookableFrom is Tuesday 07:00, so Wednesday is the first whole date.
        self::assertArrayNotHasKey('2026-11-03', $store['calendars'][7]['days']);
        self::assertArrayHasKey('2026-11-04', $store['calendars'][7]['days']);
    }

    public function testCutoffDayWithoutEntryIsFlaggedEvenWhenItsSessionsAreReturned(): void
    {
        $this->free('2026-11-02T21:30:00+0000');
        $this->free('2026-11-02T22:00:00+0000');

        [$report, $store] = $this->build(self::MONDAY_0245);

        $day = self::day($report, '2026-11-02');
        self::assertTrue($day->hoursUnknown);
        self::assertSame(['21:30-22:30 free 21:30 live, 22:00 live'], self::slots($day));
        self::assertArrayNotHasKey('2026-11-02', $store['calendars'][7]['days']);
    }

    public function testMarginDecidesWhetherTheNextDateIsWhole(): void
    {
        // 05:00 + 18 h + 1 h = Monday 00:00 exactly.
        [$onTime, $storeOnTime] = $this->build('2026-11-01 05:00');
        [$late, $storeLate] = $this->build('2026-11-01 05:01');

        self::assertFalse(self::day($onTime, '2026-11-02')->hoursUnknown);
        self::assertArrayHasKey('2026-11-02', $storeOnTime['calendars'][7]['days']);
        self::assertTrue(self::day($late, '2026-11-02')->hoursUnknown);
        self::assertArrayNotHasKey('2026-11-02', $storeLate['calendars'][7]['days']);
    }

    public function testFarEndNeedsTheWholeDateInsideBookableUntil(): void
    {
        // With 4 days ahead, bookableUntil is run start + 3 days.
        $store = self::store(['2026-11-02' => self::entry(self::AS_OF, '21:30-22:00')]);

        [, $exact] = $this->build('2026-10-31 00:00', $store, ['max_days_ahead' => 4]);
        [, $short] = $this->build('2026-10-30 23:59', $store, ['max_days_ahead' => 4]);

        self::assertSame(self::entry('2026-10-31T00:00:00+00:00'), $exact['calendars'][7]['days']['2026-11-02']);
        self::assertSame(self::entry(self::AS_OF, '21:30-22:00'), $short['calendars'][7]['days']['2026-11-02']);
    }

    public function testShortClockChangeDateEndsAtItsNextLocalMidnight(): void
    {
        // bookableUntil = 2027-03-29 00:00 BST: 28 March (23 hours long) fits.
        [, $store] = $this->build('2027-03-25 00:00', settings: ['max_days_ahead' => 5]);

        self::assertArrayHasKey('2027-03-28', $store['calendars'][7]['days']);
        self::assertArrayNotHasKey('2027-03-29', $store['calendars'][7]['days']);
    }

    public function testMixedDurationsAreSavedApartAndABookingDropsOnlyOne(): void
    {
        $this->addType(43, 60);
        $this->free('2026-11-02T21:30:00+0000', 42);
        $this->free('2026-11-02T21:30:00+0000', 43);
        [, $store] = $this->build(settings: ['appointment_type_ids' => [42, 43]]);
        self::assertSame(['21:30-22:00', '21:30-22:30'], $store['calendars'][7]['days']['2026-11-02']['hours']);

        $this->times = [];
        $this->book('2026-11-02T22:00:00+0000');
        [$report] = $this->build('2026-11-10 12:00', $store, ['appointment_type_ids' => [42, 43]]);

        self::assertSame(
            ['21:30-22:00 free 21:30 stored', '22:00-22:30 booked 22:00 live'],
            self::slots(self::day($report, '2026-11-02')),
        );
    }

    public function testIdenticalSlotsFromTwoTypesAreSavedOnce(): void
    {
        $this->addType(43, 30);
        $this->free('2026-11-02T21:30:00+0000', 42);
        $this->free('2026-11-02T21:30:00+0000', 43);

        [$report, $store] = $this->build(settings: ['appointment_type_ids' => [42, 43]]);

        self::assertSame(['21:30-22:00 free 21:30 live'], self::slots(self::day($report, '2026-11-02')));
        self::assertSame(['21:30-22:00'], $store['calendars'][7]['days']['2026-11-02']['hours']);
    }

    public function testSlotCrossingMidnightIsLeftOutOfTheEntryOnly(): void
    {
        $store = self::store(['2026-11-02' => self::entry(self::AS_OF, '21:30-22:00', '22:00-22:30')]);
        $this->free('2026-11-02T22:00:00+0000');
        $this->book('2026-11-02T23:45:00+0000');

        [$report, $after] = $this->build(store: $store);

        // The entry is still replaced: the stale 21:30 hour does not survive.
        self::assertSame(self::entry(self::OBSERVED, '22:00-22:30'), $after['calendars'][7]['days']['2026-11-02']);
        self::assertStringContainsString('2026-11-02: a slot crosses midnight', implode("\n", $report->warnings));
        self::assertSame(['22:00-22:30 free 22:00 live', '23:45-00:15 booked 23:45 live'], self::slots(self::day($report, '2026-11-02')));
    }

    public function testOtherEntriesAreCarriedOverUnchanged(): void
    {
        $store = self::store(['2026-11-02' => self::entry(self::AS_OF), '2025-12-01' => self::entry(self::AS_OF, '21:30-22:00')]);
        $store['calendars'][99] = ['name' => 'Gone', 'days' => ['2026-11-02' => self::entry(self::AS_OF, '10:00-11:00')]];

        [, $after] = $this->build('2026-11-10 12:00', $store);

        self::assertSame($store['calendars'][99], $after['calendars'][99]);
        self::assertSame($store['calendars'][7]['days']['2025-12-01'], $after['calendars'][7]['days']['2025-12-01']);
        self::assertSame($store['calendars'][7]['days']['2026-11-02'], $after['calendars'][7]['days']['2026-11-02']);
    }

    public function testNewlyListedTypeUsesExistingEntries(): void
    {
        $this->addType(43, 30);
        $store = self::store(['2026-11-02' => self::entry(self::AS_OF, '21:30-22:00')]);

        [$report] = $this->build('2026-11-10 12:00', $store, ['appointment_type_ids' => [42, 43]]);

        self::assertSame(['21:30-22:00 free 21:30 stored'], self::slots(self::day($report, '2026-11-02')));
    }

    // --- Rebuild with an empty store ---

    public function testTouchingFreeSlotsMergeAndKeepTheirStarts(): void
    {
        $this->free('2026-11-02T21:30:00+0000');
        $this->free('2026-11-02T22:00:00+0000');

        [$report] = $this->build();

        self::assertSame(['21:30-22:30 free 21:30 live, 22:00 live'], self::slots(self::day($report, '2026-11-02')));
    }

    public function testFirstRunShowsMondayAndFlagsTheCutoffDay(): void
    {
        $this->free('2026-11-02T21:30:00+0000');
        $this->free('2026-11-02T22:00:00+0000');

        [$report] = $this->build();

        $monday = self::day($report, '2026-11-02');
        self::assertFalse($monday->hoursUnknown);
        self::assertCount(1, $monday->slots);
        self::assertTrue(self::day($report, '2026-11-01')->hoursUnknown);
    }

    public function testDayAfterBookableUntilIsFlaggedAndWarned(): void
    {
        // bookableUntil = Monday 9 Nov 16:00.
        [$report] = $this->build(settings: ['max_days_ahead' => 10]);

        self::assertFalse(self::day($report, '2026-11-08')->hoursUnknown);
        self::assertTrue(self::day($report, '2026-11-09')->hoursUnknown);
        self::assertStringContainsString('season ends after the booking horizon', implode("\n", $report->warnings));
    }

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

    public function testClockChangeKeepsLocalSessionTimes(): void
    {
        foreach (['2027-03-22T21:30:00+0000', '2027-03-22T22:00:00+0000', '2027-03-29T21:30:00+0100', '2027-03-29T22:00:00+0100'] as $time) {
            $this->free($time);
        }

        [$report, $store] = $this->build('2027-03-01 12:00');

        foreach (['2027-03-22', '2027-03-29'] as $date) {
            self::assertSame(['21:30-22:30 free 21:30 live, 22:00 live'], self::slots(self::day($report, $date)), $date);
            self::assertSame(['21:30-22:00', '22:00-22:30'], $store['calendars'][7]['days'][$date]['hours'], $date);
        }
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

    public function testInvalidTypesAreWarnedAboutAndCalendarsWithoutTypesLeftOut(): void
    {
        $this->calendars[] = ['id' => 8, 'name' => 'Tutor B'];
        $this->calendars[] = ['id' => 9, 'name' => 'Tutor C'];
        $this->types[] = ['id' => 43, 'name' => 'Old', 'duration' => 30, 'active' => false, 'type' => 'service', 'calendarIDs' => [8]];
        $this->types[] = ['id' => 45, 'name' => 'Class', 'duration' => 60, 'active' => true, 'type' => 'class', 'calendarIDs' => [9]];

        [$report] = $this->build(settings: ['appointment_type_ids' => [42, 43, 44, 45]]);

        self::assertSame([7], array_map(static fn ($t): int => $t->tutor->id, $report->tutors));
        self::assertSame([42], array_column($report->types, 'id'));
        $warnings = implode("\n", $report->warnings);
        self::assertStringContainsString('43 left out: not active', $warnings);
        self::assertStringContainsString('44 left out: not found', $warnings);
        self::assertStringContainsString('45 left out: not of type service', $warnings);
        self::assertStringNotContainsString('Tutor B', $warnings);
    }

    public function testExcludedCalendarIsLeftOut(): void
    {
        $this->calendars[] = ['id' => 8, 'name' => 'Tutor B'];
        $this->types[0]['calendarIDs'] = [7, 8];

        [$report] = $this->build(settings: ['exclude_calendars' => [7]]);

        self::assertSame([8], array_map(static fn ($t): int => $t->tutor->id, $report->tutors));
    }

    public function testNoQualifyingCalendarFails(): void
    {
        $this->types[0]['active'] = false;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No calendar offers');

        $this->build();
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
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/fixtures/appointments.json'), true);
        $this->appointments = $fixture;
        $this->types = [['id' => 101, 'name' => 'Fixture lesson', 'duration' => 30, 'active' => true, 'type' => 'service', 'calendarIDs' => [7]]];

        [$report, $store] = $this->build('2026-10-08 12:00', settings: [
            'season_start' => '2026-03', 'season_end' => '2026-03', 'appointment_type_ids' => [101],
        ]);

        self::assertCount(1, self::day($report, '2026-03-30')->slots);
        $dump = print_r($report, true) . print_r($store, true);
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
        $store = self::store(['2026-11-02' => self::entry(self::AS_OF, '21:30-22:00', '22:00-22:30')]);
        $this->free('2026-11-02T22:00:00+0000');

        [$report] = $this->availability(self::MONDAY_0245)->build($store, 7);
        $out = DebugTutorCommand::format($report);

        self::assertStringContainsString('Calendar 7: Tutor A', $out);
        self::assertStringContainsString('2026-11-02 Mon  21:30 free stored ' . self::AS_OF . ", 22:00 free live\n", $out);
        self::assertStringContainsString("2026-11-01 Sun  -  [hours unknown]\n", $out);
        self::assertStringContainsString("2026-11-03 Tue  -\n", $out);
    }
}
