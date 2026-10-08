<?php

declare(strict_types=1);

namespace AcuityReport\Tests;

use AcuityReport\AcuityClient;
use AcuityReport\Config;
use AcuityReport\Credentials;
use AcuityReport\ListCommand;
use AcuityReport\SpikeCommand;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CommandsTest extends TestCase
{
    private FakeTransport $transport;
    private string $dir;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->dir = sys_get_temp_dir() . '/spike-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function client(): AcuityClient
    {
        return new AcuityClient(new Credentials('1', 'k'), $this->transport, static fn (): float => 0.0, static function (float $s): void {
        });
    }

    private static function config(array $typeIds): Config
    {
        return Config::fromArray([
            'season_start' => '2026-11',
            'season_end' => '2027-03',
            'appointment_type_ids' => $typeIds,
            'min_notice_hours' => 18,
            'max_days_ahead' => 287,
        ]);
    }

    public function testListShowsIdsAndTypeFieldsButNoEmails(): void
    {
        $this->transport
            ->route('/calendars', [['id' => 7, 'name' => "Tutor\nOne", 'email' => 'tutor@example.com']])
            ->route('/appointment-types', [[
                'id' => 42, 'name' => 'Lesson', 'duration' => 30, 'paddingBefore' => 0, 'paddingAfter' => 0,
                'active' => true, 'private' => false, 'type' => 'service', 'calendarIDs' => [7, 8],
            ]]);

        $out = ListCommand::run($this->client());

        self::assertStringContainsString('Tutor One', $out);
        self::assertMatchesRegularExpression('/^42\s+Lesson\s+30\s+0\s+0\s+yes\s+no\s+service\s+7,8$/m', $out);
        self::assertStringNotContainsString('example.com', $out);
    }

    public function testListFailsOnUnexpectedShape(): void
    {
        $this->transport->route('/calendars', [['name' => 'no id']])->route('/appointment-types', []);

        $this->expectException(RuntimeException::class);

        ListCommand::run($this->client());
    }

    public function testSpikeRequiresTypeIds(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('--list');

        SpikeCommand::run($this->client(), self::config([]), $this->dir, new DateTimeImmutable('2026-10-08'));
    }

    public function testSpikeSavesShapesWithoutClientFields(): void
    {
        $this->transport
            ->route('/appointment-types', [['id' => 42, 'private' => true, 'calendarIDs' => [7]]])
            ->route('/availability/dates', [['date' => '2026-11-02']])
            ->route('/availability/times', [['time' => '2026-11-02T21:30:00+0000'], ['time' => '2026-11-02T22:00:00+0000']])
            ->route('/appointments', [[
                'id' => 1, 'datetime' => '2026-11-09T21:30:00+0000', 'endTime' => '10:00pm', 'duration' => '30',
                'timezone' => 'Europe/London', 'canceled' => false, 'appointmentTypeID' => 42,
                'firstName' => 'DUMMYCLIENT', 'email' => 'client@example.com', 'phone' => '0123',
            ]]);

        $summary = SpikeCommand::run($this->client(), self::config([42]), $this->dir, new DateTimeImmutable('2026-10-08'));

        self::assertStringContainsString('calendar 7, month 2026-11', $summary);
        self::assertStringContainsString('private: yes', $summary);
        self::assertStringContainsString('2026-11-02T21:30:00+0000, 2026-11-02T22:00:00+0000', $summary);

        $shape = (string) file_get_contents($this->dir . '/appointments-shape.json');
        self::assertStringContainsString('"firstName"', $shape); // field name listed
        self::assertStringNotContainsString('DUMMYCLIENT', $shape); // value dropped
        self::assertStringNotContainsString('example.com', $shape);
        self::assertStringNotContainsString('0123', $shape);
        self::assertFileExists($this->dir . '/availability-times-type42-2026-11-02.json');
        $decoded = json_decode($shape, true);
        self::assertSame(
            ['id' => 1, 'datetime' => '2026-11-09T21:30:00+0000', 'endTime' => '10:00pm', 'duration' => '30',
                'timezone' => 'Europe/London', 'canceled' => false, 'appointmentTypeID' => 42],
            $decoded['samples'][0],
        );

        $appointmentsUrl = array_values(array_filter($this->transport->urls, static fn ($u) => str_contains($u, '/appointments?')))[0];
        self::assertStringContainsString('minDate=2026-11-01&maxDate=2026-11-30', $appointmentsUrl);
        self::assertStringContainsString('excludeForms=true', $appointmentsUrl);
    }

    public function testFailedSpikeWritesNothing(): void
    {
        $this->transport
            ->route('/appointment-types', [['id' => 42, 'calendarIDs' => [7]]])
            ->route('/availability/dates', [['date' => '2026-11-02']])
            ->route('/availability/times', [['time' => '2026-11-02T22:00:00+0000']])
            ->route('/appointments', ['message' => 'boom'], 400);

        try {
            SpikeCommand::run($this->client(), self::config([42]), $this->dir, new DateTimeImmutable('2026-10-08'));
            self::fail('Expected exception');
        } catch (RuntimeException) {
        }
        self::assertDirectoryDoesNotExist($this->dir);
    }

    public function testSpikeFetchesTimesForEveryDate(): void
    {
        $this->transport
            ->route('/appointment-types', [['id' => 42, 'calendarIDs' => [7]]])
            ->route('/availability/dates', [['date' => '2026-11-02'], ['date' => '2026-11-09'], ['date' => '2026-11-16']])
            ->route('/availability/times', [['time' => '2026-11-02T22:00:00+0000']])
            ->route('/appointments', []);

        SpikeCommand::run($this->client(), self::config([42]), $this->dir, new DateTimeImmutable('2026-10-08'));

        $timesCalls = array_filter($this->transport->urls, static fn ($u) => str_contains($u, '/availability/times'));
        self::assertCount(3, $timesCalls);
    }

    public function testSpikeFailsForUnknownTypeId(): void
    {
        $this->transport->route('/appointment-types', [['id' => 1, 'calendarIDs' => [7]]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found in Acuity: 42');

        SpikeCommand::run($this->client(), self::config([42]), $this->dir, new DateTimeImmutable('2026-10-08'));
    }
}
