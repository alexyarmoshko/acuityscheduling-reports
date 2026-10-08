<?php

declare(strict_types=1);

namespace AcuityReport\Tests;

use AcuityReport\AcuityClient;
use AcuityReport\Config;
use AcuityReport\Credentials;
use AcuityReport\Day;
use AcuityReport\Report;
use AcuityReport\ReportCommand;
use AcuityReport\ReportRenderer;
use AcuityReport\SeasonAvailability;
use AcuityReport\Slot;
use AcuityReport\SnapshotStore;
use AcuityReport\Tutor;
use AcuityReport\TutorSeason;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class ReportRendererTest extends TestCase
{
    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('Europe/London'));
    }

    private static function report(string $name = 'Tutor A', array $days = [], array $warnings = [], string $until = '2027-07-21 15:09'): Report
    {
        return new Report(
            '2026-11',
            '2027-03',
            self::at('2026-10-08 14:32:07'),
            'Europe/London',
            [['id' => 42, 'name' => 'Lesson', 'duration' => 30]],
            self::at('2026-10-09 09:32'),
            self::at($until),
            [new TutorSeason(new Tutor(7, $name), $days)],
            $warnings,
        );
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

        $html = ReportRenderer::render(self::report($name, warnings: ["Warning about {$name}"]));

        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertSame(2, substr_count($html, '</script>'), 'only the data block and the page script close');
        self::assertSame($name, self::data($html)['tutors'][0]['name']);
    }

    public function testNoExternalUrls(): void
    {
        $html = ReportRenderer::render(self::report());

        self::assertDoesNotMatchRegularExpression('#(https?:)?//[a-z0-9]#i', $html);
        self::assertStringNotContainsString(' src=', $html);
        self::assertStringNotContainsString('<link', $html);
    }

    public function testDefaultFileNameIsTimestamped(): void
    {
        self::assertMatchesRegularExpression(
            '/\Aavailability-\d{4}-\d{2}-\d{2}_\d{6}\.html\z/',
            ReportRenderer::defaultFileName(self::at('2026-10-07 14:32:07')),
        );
        self::assertSame('availability-2026-10-07_143207.html', ReportRenderer::defaultFileName(self::at('2026-10-07 14:32:07')));
    }

    public function testFooterStatesTheSavedHoursRule(): void
    {
        $html = ReportRenderer::render(self::report());

        self::assertStringContainsString('Hours before 9 Oct 09:32 that were too soon to read are filled in', $html);
        self::assertStringNotContainsString('too far ahead to read are filled in', $html);
        self::assertStringContainsString('Time a tutor has blocked off is not shown.', $html);
    }

    public function testFooterAddsTheHorizonSentenceWhenTheSeasonEndsAfterIt(): void
    {
        $html = ReportRenderer::render(self::report(until: '2027-02-01 15:09'));

        self::assertStringContainsString('Hours after 1 Feb 15:09 that were too far ahead to read are filled in the same way.', $html);
    }

    public function testDataHoldsPreformattedSlotsButNotStoredAt(): void
    {
        $slots = [
            new Slot(self::at('2026-11-02 21:30'), self::at('2026-11-02 22:30'), Slot::FREE, ['21:30' => Slot::STORED, '22:00' => Slot::LIVE]),
            new Slot(self::at('2027-03-29 22:00'), self::at('2027-03-29 22:30'), Slot::BOOKED, ['22:00' => Slot::LIVE], 'Lesson'),
        ];
        $days = [
            '2026-11-01' => new Day('2026-11-01', true, null, []),
            '2026-11-02' => new Day('2026-11-02', false, '2026-10-05T12:00:00+01:00', [$slots[0]]),
            '2026-11-03' => new Day('2026-11-03', false, null, []),
            '2027-03-29' => new Day('2027-03-29', false, null, [$slots[1]]),
        ];

        $html = ReportRenderer::render(self::report(days: $days));
        $data = self::data($html);

        self::assertStringNotContainsString('2026-10-05T12:00', $html);
        self::assertSame(['2026-11', '2026-12', '2027-01', '2027-02', '2027-03'], $data['months']);
        self::assertSame('2026-10-08', $data['today']);
        $tutorDays = $data['tutors'][0]['days'];
        self::assertSame(['2026-11-01', '2026-11-02', '2027-03-29'], array_keys($tutorDays), 'empty days are left out');
        self::assertSame(['unknown' => true, 'slots' => []], $tutorDays['2026-11-01']);
        self::assertSame(
            ['from' => '21:30', 'to' => '22:30', 'status' => 'free', 'sessions' => 2, 'type' => null],
            $tutorDays['2026-11-02']['slots'][0],
        );
        // Local BST time after the clock change, not UTC.
        self::assertSame('22:00', $tutorDays['2027-03-29']['slots'][0]['from']);
    }

    public function testHeaderShowsSeasonTypesTimezoneAndGenerationTime(): void
    {
        $html = ReportRenderer::render(self::report());

        self::assertStringContainsString('Reconstructed availability', $html);
        self::assertStringContainsString('November 2026 – March 2027', $html);
        self::assertStringContainsString('Lesson (30 min)', $html);
        self::assertStringContainsString('Europe/London', $html);
        self::assertStringContainsString('8 Oct 2026 14:32', $html);
    }

    public function testTutorsAreSortedByName(): void
    {
        $report = self::report();
        $report = new Report(
            $report->seasonStart, $report->seasonEnd, $report->generatedAt, $report->timezone, $report->types,
            $report->bookableFrom, $report->bookableUntil,
            [new TutorSeason(new Tutor(2, 'zoe'), []), new TutorSeason(new Tutor(1, 'Adam'), [])],
            [],
        );

        self::assertSame(['Adam', 'zoe'], array_column(self::data(ReportRenderer::render($report))['tutors'], 'name'));
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
        $client = new AcuityClient(new Credentials('1', 'k'), $transport, static fn (): float => 0.0, static function (float $s): void {
        });
        $config = Config::fromArray([
            'season_start' => '2026-03', 'season_end' => '2026-03', 'appointment_type_ids' => [101],
            'min_notice_hours' => 18, 'max_days_ahead' => 287,
        ]);

        [$report] = (new SeasonAvailability($client, $config, self::at('2026-10-08 12:00')))->build(SnapshotStore::empty());
        $html = ReportRenderer::render($report);

        self::assertStringContainsString('Fixture Tutor', $html);
        self::assertStringContainsString('"from":"21:30"', $html);
        foreach (['FIXTURE-CLIENT', 'FIXTURE-NOTES', '000-FIXTURE', 'example.invalid'] as $value) {
            self::assertStringNotContainsString($value, $html, $value);
        }
        self::assertDoesNotMatchRegularExpression('/[\w.+-]+@[\w-]+\.[\w.]+/', $html, 'no email address');
    }

    public function testRunPublishesReportAndStoreOnFirstRun(): void
    {
        $root = sys_get_temp_dir() . '/report-run-' . bin2hex(random_bytes(4));
        $transport = (new FakeTransport())
            ->route('/calendars', [['id' => 7, 'name' => 'Tutor A']])
            ->route('/appointment-types', [['id' => 42, 'name' => 'Lesson', 'duration' => 30, 'active' => true, 'type' => 'service', 'calendarIDs' => [7]]])
            ->route('/availability/dates', static fn (array $q): array => $q['month'] === '2026-11' ? [['date' => '2026-11-02']] : [])
            ->route('/availability/times', [['time' => '2026-11-02T21:30:00+0000']])
            ->route('/appointments', []);
        $client = new AcuityClient(new Credentials('1', 'k'), $transport, static fn (): float => 0.0, static function (float $s): void {
        });
        $config = Config::fromArray([
            'season_start' => '2026-11', 'season_end' => '2027-03', 'appointment_type_ids' => [42],
            'min_notice_hours' => 18, 'max_days_ahead' => 287,
        ]);
        $runStart = self::at('2026-10-08 14:32:07');

        try {
            $summary = ReportCommand::run($client, $config, $root . '/data', $root . '/out', null, $runStart, static fn (): DateTimeImmutable => $runStart->modify('+2 minutes'));

            $report = $root . '/out/availability-2026-10-08_143207.html';
            self::assertFileExists($report);
            self::assertStringContainsString("Report written to {$report}", $summary);
            self::assertFileDoesNotExist($root . '/data/' . SnapshotStore::FILE . '.bak');
            $store = SnapshotStore::load($root . '/data');
            self::assertSame(['21:30-22:00'], $store['calendars'][7]['days']['2026-11-02']['hours']);
            // The lock was released: a second run can start.
            fclose(SnapshotStore::lock($root . '/data'));
        } finally {
            foreach (['/out/availability-2026-10-08_143207.html', '/data/' . SnapshotStore::FILE, '/data/.lock'] as $file) {
                @unlink($root . $file);
            }
            @rmdir($root . '/out');
            @rmdir($root . '/data');
            @rmdir($root);
        }
    }
}
