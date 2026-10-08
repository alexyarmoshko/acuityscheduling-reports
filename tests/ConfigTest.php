<?php

declare(strict_types=1);

namespace AcuityReport\Tests;

use AcuityReport\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConfigTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function valid(): array
    {
        return [
            'season_start' => '2026-11',
            'season_end' => '2027-03',
            'appointment_type_ids' => [42, 43],
            'min_notice_hours' => 18,
            'max_days_ahead' => 287,
            'exclude_calendars' => [7],
        ];
    }

    public function testAcceptsValidSettings(): void
    {
        $config = Config::fromArray(self::valid());

        self::assertSame('2026-11', $config->seasonStart);
        self::assertSame('2027-03', $config->seasonEnd);
        self::assertSame([42, 43], $config->appointmentTypeIds);
        self::assertSame(18, $config->minNoticeHours);
        self::assertSame(287, $config->maxDaysAhead);
        self::assertSame([7], $config->excludeCalendars);
    }

    public function testEmptyTypeIdsAllowedForList(): void
    {
        $values = self::valid();
        $values['appointment_type_ids'] = [];

        self::assertSame([], Config::fromArray($values)->appointmentTypeIds);
    }

    public function testDuplicateIdsAreRemoved(): void
    {
        $values = self::valid();
        $values['appointment_type_ids'] = [42, 42, 43];

        self::assertSame([42, 43], Config::fromArray($values)->appointmentTypeIds);
    }

    public function testExcludeCalendarsIsOptional(): void
    {
        $values = self::valid();
        unset($values['exclude_calendars']);

        self::assertSame([], Config::fromArray($values)->excludeCalendars);
    }

    public function testExampleFileIsValidApartFromPlaceholderTypeId(): void
    {
        $values = require __DIR__ . '/../config.example.php';
        $values['appointment_type_ids'] = [1];

        self::assertInstanceOf(Config::class, Config::fromArray($values));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidValues(): iterable
    {
        yield 'month format' => ['season_start', '2026-1'];
        yield 'month trailing newline' => ['season_start', "2026-11\n"];
        yield 'month 13' => ['season_end', '2027-13'];
        yield 'start after end' => ['season_start', '2027-04'];
        yield 'type ids scalar' => ['appointment_type_ids', 42];
        yield 'type id zero' => ['appointment_type_ids', [0]];
        yield 'type id string' => ['appointment_type_ids', ['42']];
        yield 'type ids not list' => ['appointment_type_ids', ['a' => 42]];
        yield 'negative notice' => ['min_notice_hours', -1];
        yield 'zero days ahead' => ['max_days_ahead', 0];
        yield 'exclude not list' => ['exclude_calendars', ['a' => 1]];
        yield 'exclude string id' => ['exclude_calendars', ['7']];
    }

    #[DataProvider('invalidValues')]
    public function testRejectsInvalidValue(string $key, mixed $value): void
    {
        $values = self::valid();
        $values[$key] = $value;

        $this->expectException(RuntimeException::class);

        Config::fromArray($values);
    }

    public function testRejectsMissingKey(): void
    {
        $values = self::valid();
        unset($values['max_days_ahead']);

        $this->expectException(RuntimeException::class);

        Config::fromArray($values);
    }

    public function testRejectsUnknownKey(): void
    {
        $values = self::valid();
        $values['exclude_calendar'] = [7];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exclude_calendar');

        Config::fromArray($values);
    }

    public function testRemovedSessionWindowIsNamedAsUnknown(): void
    {
        $values = self::valid();
        $values['session_window'] = ['21:30', '22:30'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown setting(s): session_window');

        Config::fromArray($values);
    }

    public function testLoadExplainsMissingFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Copy config.example.php to config.php');

        Config::load(__DIR__ . '/no-config.php');
    }

    public function testLoadsFileWithUtf8BomWithoutPrintingIt(): void
    {
        $path = $this->tempFile("\u{FEFF}<?php return " . var_export(self::valid(), true) . ';');

        $this->expectOutputString('');

        self::assertSame([42, 43], Config::load($path)->appointmentTypeIds);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeFiles(): iterable
    {
        yield 'no php tag' => ["ACUITY_API_KEY=DUMMY-SECRET\n"];
        yield 'utf-16' => [mb_convert_encoding("<?php return ['k' => 'DUMMY-SECRET'];", 'UTF-16LE', 'UTF-8')];
        yield 'syntax error' => ["<?php return ['k' => DUMMY-SECRET oops];"];
        yield 'undefined constant' => ["<?php return ['k' => DUMMYSECRET];"];
        yield 'throws with secret' => ["<?php throw new RuntimeException('DUMMY-SECRET');"];
        yield 'opens own buffer then throws' => ["<?php ob_start(); echo 'DUMMY-SECRET'; throw new Exception('x');"];
        yield 'opens own buffer and returns' => ["<?php ob_start(); echo 'DUMMY-SECRET'; return 'not an array';"];
    }

    #[DataProvider('unsafeFiles')]
    public function testUnsafeFileIsRejectedWithoutEchoingContents(string $contents): void
    {
        $path = $this->tempFile($contents);
        $level = ob_get_level();

        $this->expectOutputString('');

        try {
            Config::load($path);
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertStringNotContainsString('DUMMY', $e->getMessage());
            self::assertStringNotContainsString('SECRET', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
        self::assertSame($level, ob_get_level());
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cfg');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
    }
}
