<?php

declare(strict_types=1);

namespace AcuityReport\Tests;

use AcuityReport\AcuityClient;
use AcuityReport\Config;
use AcuityReport\Credentials;
use AcuityReport\RenderOnlyCommand;
use AcuityReport\ReportCommand;
use AcuityReport\ReportRenderer;
use AcuityReport\SeasonAvailability;
use AcuityReport\SnapshotStore;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReportRendererTest extends TestCase
{
    use RemovesTempDirs;

    private const GENERATED = '2026-10-08T14:32:07+01:00';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/report-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('Europe/London'));
    }

    /** @return array<string, mixed> a store with one calendar and a lastRun */
    private static function store(array $calendar = [], array $lastRun = []): array
    {
        return [
            'version' => 1,
            'lastRun' => $lastRun + [
                'generatedAt' => self::GENERATED,
                'seasonStart' => '2026-11',
                'seasonEnd' => '2027-03',
                'types' => [['id' => 42, 'name' => 'Lesson', 'duration' => 30]],
                'bookableFrom' => '2026-10-09T09:32:07+01:00',
                'bookableUntil' => '2027-07-21T15:09:07+01:00',
                'warnings' => [],
            ],
            'calendars' => [7 => $calendar + [
                'name' => 'Tutor A',
                'reportedAt' => self::GENERATED,
                'types' => [['id' => 42, 'name' => 'Lesson', 'duration' => 30]],
                'days' => [],
            ]],
        ];
    }

    /** @return array<string, mixed> the decoded JSON data block */
    private static function data(string $html): array
    {
        self::assertSame(1, preg_match('#<script type="application/json" id="report-data">(.*?)</script>#s', $html, $m));

        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    }

    public function testNameCannotBreakOutOfTheDataBlockAndRoundTrips(): void
    {
        $name = 'Evil </script><script>alert(1)</script> & "quotes" \'single\' <!--';
        $store = self::store(['name' => $name, 'types' => [['id' => 42, 'name' => $name, 'duration' => 30]]], ['warnings' => ["Warning about {$name}"]]);

        $html = ReportRenderer::render($store);

        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertSame(2, substr_count($html, '</script>'), 'only the data block and the page script close');
        $tutor = self::data($html)['tutors'][0];
        self::assertSame($name, $tutor['name']);
        self::assertSame("{$name} (30 min)", $tutor['types']);
    }

    public function testNoExternalUrls(): void
    {
        $html = ReportRenderer::render(self::store());

        self::assertDoesNotMatchRegularExpression('#(https?:)?//[a-z0-9]#i', $html);
        self::assertStringNotContainsString(' src=', $html);
        self::assertStringNotContainsString('<link', $html);
    }

    public function testDefaultFileNameIsTimestamped(): void
    {
        self::assertSame('availability-2026-10-07_143207.html', ReportRenderer::defaultFileName(self::at('2026-10-07 14:32:07')));
    }

    public function testPageHasNoFooterAndNoWarnings(): void
    {
        $html = ReportRenderer::render(self::store(lastRun: ['warnings' => ['A run warning']]));

        self::assertStringNotContainsString('<footer', $html);
        self::assertStringNotContainsString('A run warning', $html);
        self::assertStringNotContainsString('blocked off', $html);
    }

    public function testHeaderShowsTitleSeasonTimezoneAndGenerationTimeButNoTypes(): void
    {
        $html = ReportRenderer::render(self::store());

        self::assertStringContainsString('<h1>Tutors Availability</h1>', $html);
        self::assertStringContainsString('<title>Tutors Availability November 2026 – March 2027</title>', $html);
        self::assertStringContainsString('November 2026 – March 2027', $html);
        self::assertStringContainsString('Time zone: Europe/Dublin', $html);
        self::assertStringNotContainsString('Europe/London', $html);
        self::assertStringContainsString('8 Oct 2026 14:32', $html);
        self::assertStringNotContainsString('Types:', $html);
        self::assertSame('Lesson (30 min)', self::data($html)['tutors'][0]['types']);
    }

    public function testDaysHoldTimesOnlyAndFlagMissingOrIncompleteDays(): void
    {
        $days = [
            '2026-11-02' => ['observedAt' => self::GENERATED, 'hours' => ['21:30-22:00', '21:30-22:30', '22:00-22:30'], 'complete' => true],
            '2026-11-03' => ['observedAt' => self::GENERATED, 'hours' => [], 'complete' => true],
            '2026-11-09' => ['observedAt' => self::GENERATED, 'hours' => ['22:00-22:30'], 'complete' => false],
            '2026-11-16' => ['observedAt' => '2026-10-01T10:00:00+01:00', 'hours' => ['21:30-22:00']],
        ];
        $complete = [];
        for ($d = self::at('2026-11-01'); $d < self::at('2027-04-01'); $d = $d->modify('+1 day')) {
            $complete[$d->format('Y-m-d')] = ['observedAt' => self::GENERATED, 'hours' => [], 'complete' => true];
        }
        unset($complete['2026-11-04']); // never saved

        $html = ReportRenderer::render(self::store(['days' => $days + $complete]));
        $tutorDays = self::data($html)['tutors'][0]['days'];

        self::assertSame(['2026-11-02', '2026-11-04', '2026-11-09', '2026-11-16'], array_keys($tutorDays));
        self::assertSame(['unknown' => false, 'slots' => ['21:30–22:00', '21:30–22:30', '22:00–22:30']], $tutorDays['2026-11-02']);
        self::assertSame(['unknown' => true, 'slots' => []], $tutorDays['2026-11-04']);
        self::assertSame(['unknown' => true, 'slots' => ['22:00–22:30']], $tutorDays['2026-11-09']);
        self::assertSame(['unknown' => false, 'slots' => ['21:30–22:00']], $tutorDays['2026-11-16'], 'no complete key: complete');
        self::assertStringNotContainsString('2026-10-01T10:00', $html);
        self::assertStringNotContainsString('"status"', $html, 'slots carry no free/booked status');
    }

    public function testAsOfLabels(): void
    {
        $store = self::store();
        $store['calendars'][8] = ['name' => 'Older', 'reportedAt' => '2026-10-01T10:00:00+01:00', 'days' => []];
        $store['calendars'][9] = ['name' => 'Legacy', 'days' => []];

        $tutors = array_column(self::data(ReportRenderer::render($store))['tutors'], 'asOf', 'name');

        self::assertSame(['Legacy' => 'an earlier run', 'Older' => '1 Oct 2026 10:00', 'Tutor A' => null], $tutors);
    }

    public function testTutorsAreSortedAndFilteredByIncludeAndExclude(): void
    {
        $store = self::store();
        $store['calendars'][8] = ['name' => 'zoe', 'reportedAt' => self::GENERATED, 'days' => []];
        $store['calendars'][9] = ['name' => 'Adam', 'reportedAt' => self::GENERATED, 'days' => []];

        $names = static fn (string $html): array => array_column(self::data($html)['tutors'], 'name');

        self::assertSame(['Adam', 'Tutor A', 'zoe'], $names(ReportRenderer::render($store)));
        self::assertSame(['Tutor A', 'zoe'], $names(ReportRenderer::render($store, [7, 8])));
        self::assertSame(['Tutor A'], $names(ReportRenderer::render($store, [7, 8], [8])));
    }

    public function testNoCalendarsRendersTheEmptyMessage(): void
    {
        $store = self::store();
        $store['calendars'] = [];

        $html = ReportRenderer::render($store);

        self::assertSame([], self::data($html)['tutors']);
        self::assertStringContainsString('No tutor calendars to show.', $html);
    }

    public function testNoLastRunFails(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No saved report yet');

        ReportRenderer::render(SnapshotStore::empty());
    }

    public function testNoClientFieldsOrEmailsReachTheHtml(): void
    {
        $transport = (new FakeTransport())
            ->route('/calendars', [['id' => 7, 'name' => 'Fixture Tutor', 'email' => 'fixture-tutor@example.invalid', 'replyTo' => 'reply@example.invalid']])
            ->route('/appointment-types', [['id' => 101, 'name' => 'Fixture lesson', 'duration' => 30, 'active' => true, 'type' => 'service', 'calendarIDs' => [7]]])
            ->route('/appointments', static function (array $q): array {
                $rows = json_decode((string) file_get_contents(__DIR__ . '/fixtures/appointments.json'), true);
                return array_values(array_filter($rows, static fn (array $r): bool => substr($r['datetime'], 0, 7) === substr($q['minDate'], 0, 7)));
            });
        $config = Config::fromArray([
            'season_start' => '2026-03', 'season_end' => '2026-03', 'appointment_type_ids' => [101],
            'min_notice_hours' => 18, 'max_days_ahead' => 287,
        ]);

        [, $store] = (new SeasonAvailability($this->client($transport), $config, self::at('2026-10-08 12:00')))->build(SnapshotStore::empty());
        $html = ReportRenderer::render($store);

        self::assertStringContainsString('Fixture Tutor', $html);
        self::assertSame(['21:30' . mb_chr(0x2013) . '22:00'], self::data($html)['tutors'][0]['days']['2026-03-30']['slots']);
        foreach (['FIXTURE-CLIENT', 'FIXTURE-NOTES', '000-FIXTURE', 'example.invalid'] as $value) {
            self::assertStringNotContainsString($value, $html, $value);
        }
        self::assertDoesNotMatchRegularExpression('/[\w.+-]+@[\w-]+\.[\w.]+/', $html, 'no email address');
    }

    // --- Report run and --render-only ---

    private function client(FakeTransport $transport): AcuityClient
    {
        return new AcuityClient(new Credentials('1', 'k'), $transport, static fn (): float => 0.0, static function (float $s): void {
        });
    }

    private static function config(array $settings = []): Config
    {
        return Config::fromArray($settings + [
            'season_start' => '2026-11', 'season_end' => '2027-03', 'appointment_type_ids' => [42],
            'min_notice_hours' => 18, 'max_days_ahead' => 287,
        ]);
    }

    /** A small fake API: calendars 7 and 8, type 42 offered on $offeredOn. */
    private static function api(array $offeredOn = [7, 8], bool $active = true, array $calendars = [7 => 'Tutor A', 8 => 'Tutor B']): FakeTransport
    {
        return (new FakeTransport())
            ->route('/calendars', array_map(static fn (int $id, string $name): array => ['id' => $id, 'name' => $name], array_keys($calendars), $calendars))
            ->route('/appointment-types', [['id' => 42, 'name' => 'Lesson', 'duration' => 30, 'active' => $active, 'type' => 'service', 'calendarIDs' => $offeredOn]])
            ->route('/availability/dates', static fn (array $q): array => $q['month'] === '2026-11' ? [['date' => '2026-11-02']] : [])
            ->route('/availability/times', [['time' => '2026-11-02T21:30:00+0000']])
            ->route('/appointments', []);
    }

    /** One report run; returns the console summary. */
    private function runWith(FakeTransport $transport, array $settings = [], string $at = '2026-10-08 14:32:07', ?string $out = null): string
    {
        $runStart = self::at($at);

        return ReportCommand::run(
            $this->client($transport), self::config($settings), $this->root . '/data', $this->root . '/out', $out, $runStart,
            static fn (): DateTimeImmutable => $runStart->modify('+2 minutes'),
        );
    }

    /** First report run against the small fake API; returns its report path. */
    private function reportRun(): string
    {
        $summary = $this->runWith(self::api());
        $path = $this->root . '/out/availability-2026-10-08_143207.html';
        self::assertStringContainsString("Report written to {$path}", $summary);

        return $path;
    }

    public function testFailedPublishStillPrintsTheWarnings(): void
    {
        $runStart = self::at('2026-10-08 14:32:07');
        $printed = [];

        try {
            ReportCommand::run(
                $this->client(self::api()), self::config(['include_calendars' => [7, 99]]), $this->root . '/data', $this->root . '/out', null, $runStart,
                static fn (): DateTimeImmutable => $runStart->modify('+51 minutes'),
                static function (string $message) use (&$printed): void {
                    $printed[] = $message;
                },
            );
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('50 minutes', $e->getMessage());
        }
        self::assertContains('Warning: Calendar 99 in include_calendars is not a tutor calendar (not found, or offers none of the listed types)', $printed);
    }

    public function testFailedRetrievalStillPrintsTheWarningsSoFar(): void
    {
        $runStart = self::at('2026-10-08 14:32:07');
        $printed = [];

        try {
            ReportCommand::run(
                $this->client(self::api()->route('/appointments', ['message' => 'boom'], 400)), self::config(['include_calendars' => [7, 99]]),
                $this->root . '/data', $this->root . '/out', null, $runStart, null,
                static function (string $message) use (&$printed): void {
                    $printed[] = $message;
                },
            );
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('HTTP 400', $e->getMessage());
        }
        self::assertContains('Warning: Calendar 99 in include_calendars is not a tutor calendar (not found, or offers none of the listed types)', $printed);
        self::assertFileDoesNotExist($this->root . '/data/' . SnapshotStore::FILE);
    }

    public function testLastTutorWithdrawnPublishesAnEmptyReportAndPrunesTheStore(): void
    {
        $this->reportRun();

        $summary = $this->runWith(self::api(offeredOn: []), out: $this->root . '/out/empty.html');

        self::assertStringContainsString('Removed withdrawn calendar 7: Tutor A', $summary);
        self::assertStringContainsString('Removed withdrawn calendar 8: Tutor B', $summary);
        self::assertStringContainsString('Warning: No tutor calendars to show', $summary);
        self::assertSame([], self::data((string) file_get_contents($this->root . '/out/empty.html'))['tutors']);
        self::assertSame([], SnapshotStore::load($this->root . '/data')['calendars']);
    }

    public function testLimitedRunOfWithdrawnCalendarsPublishesAnEmptyReportAndKeepsOthers(): void
    {
        $this->reportRun();

        // Calendar 8 deleted in Acuity; the run is limited to it.
        $summary = $this->runWith(self::api(offeredOn: [7], calendars: [7 => 'Tutor A']), ['include_calendars' => [8]], out: $this->root . '/out/limited.html');

        self::assertStringContainsString('Removed withdrawn calendar 8: Tutor B', $summary);
        self::assertSame([], self::data((string) file_get_contents($this->root . '/out/limited.html'))['tutors']);
        self::assertSame([7], array_keys(SnapshotStore::load($this->root . '/data')['calendars']));
    }

    public function testRunWithNoValidTypeLeavesBothFilesUnchanged(): void
    {
        $this->reportRun();
        $storeFile = $this->root . '/data/' . SnapshotStore::FILE;
        $before = (string) file_get_contents($storeFile);
        $reports = scandir($this->root . '/out');

        try {
            $this->runWith(self::api(active: false), at: '2026-10-09 10:00');
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('None of appointment_type_ids is a valid appointment type', $e->getMessage());
        }
        self::assertSame($before, file_get_contents($storeFile));
        self::assertSame($reports, scandir($this->root . '/out'));
        self::assertFileDoesNotExist($storeFile . '.bak');
    }

    public function testReportRunPublishesReportAndStoreOnFirstRun(): void
    {
        $path = $this->reportRun();

        self::assertFileExists($path);
        self::assertFileDoesNotExist($this->root . '/data/' . SnapshotStore::FILE . '.bak');
        $store = SnapshotStore::load($this->root . '/data');
        self::assertSame(['21:30-22:00'], $store['calendars'][7]['days']['2026-11-02']['hours']);
        self::assertSame('2026-10-08T14:32:07+01:00', $store['lastRun']['generatedAt']);
        fclose(SnapshotStore::lock($this->root . '/data')); // the lock was released
    }

    public function testRenderOnlyGivesTheSamePageAndLeavesTheStoreAlone(): void
    {
        $reportPath = $this->reportRun();
        $storeFile = $this->root . '/data/' . SnapshotStore::FILE;
        $storeBefore = (string) file_get_contents($storeFile);

        $summary = RenderOnlyCommand::run(self::config(), $this->root . '/data', $this->root . '/out', null, self::at('2026-10-09 10:00:00'));

        $renderPath = $this->root . '/out/availability-2026-10-09_100000.html';
        self::assertStringContainsString("Report written to {$renderPath}", $summary);
        self::assertSame(file_get_contents($reportPath), file_get_contents($renderPath));
        self::assertSame($storeBefore, file_get_contents($storeFile));
        self::assertFileDoesNotExist($storeFile . '.bak');
    }

    public function testRenderOnlyUsesTheLastRunSeasonAndCurrentFilters(): void
    {
        $this->reportRun();

        RenderOnlyCommand::run(
            self::config(['season_start' => '2027-11', 'season_end' => '2028-03', 'exclude_calendars' => [8]]),
            $this->root . '/data', $this->root . '/out', $this->root . '/out/x.html', self::at('2026-10-09 10:00'),
        );

        $data = self::data((string) file_get_contents($this->root . '/out/x.html'));
        self::assertSame('2026-11', $data['months'][0]);
        self::assertSame(['Tutor A'], array_column($data['tutors'], 'name'));
    }

    public function testRenderOnlyFailsWhileARunHoldsTheLock(): void
    {
        $this->reportRun();
        $lock = SnapshotStore::lock($this->root . '/data');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Another run');

            RenderOnlyCommand::run(self::config(), $this->root . '/data', $this->root . '/out', null, self::at('2026-10-09 10:00'));
        } finally {
            fclose($lock);
        }
    }

    public function testRenderOnlyWithoutASavedRunFails(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No saved report yet');

        RenderOnlyCommand::run(self::config(), $this->root . '/data', $this->root . '/out', null, self::at('2026-10-09 10:00'));
    }
}
