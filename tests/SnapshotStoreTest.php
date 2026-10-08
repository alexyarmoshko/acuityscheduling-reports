<?php

declare(strict_types=1);

namespace AcuityReport\Tests;

use AcuityReport\SnapshotStore;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SnapshotStoreTest extends TestCase
{
    private string $root;
    private string $data;
    private string $main;
    private DateTimeImmutable $runStart;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/snapshot-test-' . bin2hex(random_bytes(4));
        mkdir($this->root);
        $this->data = $this->root . '/data';
        $this->main = $this->data . '/' . SnapshotStore::FILE;
        $this->runStart = new DateTimeImmutable('2026-10-07 14:32:07');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    $this->remove($path . '/' . $name);
                }
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }

    private static function store(array $hours = ['21:30-22:00', '22:00-22:30']): array
    {
        return ['version' => 1, 'calendars' => [7 => ['name' => 'Tutor A', 'days' => [
            '2026-11-02' => ['observedAt' => '2026-10-07T14:32:07+01:00', 'hours' => $hours],
        ]]]];
    }

    private function publish(array $store, ?string $reportPath = null, ?DateTimeImmutable $now = null): void
    {
        SnapshotStore::publish($reportPath ?? $this->root . '/out/report.html', '<html>', $this->data, $store, $this->runStart, $now ?? $this->runStart->modify('+2 minutes'));
    }

    /** @return list<string> */
    private function tempFiles(string $dir): array
    {
        return glob($dir . '/.*.tmp') ?: [];
    }

    public function testFirstRunCreatesDataDirAndStoreWithoutBak(): void
    {
        $lock = SnapshotStore::lock($this->data);
        self::assertSame(SnapshotStore::empty(), SnapshotStore::load($this->data));

        $this->publish(self::store());
        fclose($lock);

        self::assertFileExists($this->root . '/out/report.html');
        self::assertFileDoesNotExist($this->main . '.bak');
        self::assertSame(self::store(), SnapshotStore::load($this->data));
        self::assertSame([], $this->tempFiles($this->data));
    }

    public function testBakIsACopyAndMainIsNeverMovedAway(): void
    {
        $this->publish(self::store(['21:30-22:00']));
        $first = (string) file_get_contents($this->main);

        $this->publish(self::store());

        self::assertSame($first, file_get_contents($this->main . '.bak'));
        self::assertSame(self::store(), SnapshotStore::load($this->data));
    }

    public function testRunOverFiftyMinutesWritesNothing(): void
    {
        $this->publish(self::store(['21:30-22:00']));
        $before = (string) file_get_contents($this->main);

        try {
            $this->publish(self::store(), $this->root . '/out/late.html', $this->runStart->modify('+51 minutes'));
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('50 minutes', $e->getMessage());
        }
        self::assertFileDoesNotExist($this->root . '/out/late.html');
        self::assertSame($before, file_get_contents($this->main));
    }

    public function testReportInAnotherDirectoryIsRenamedFromATempFileThere(): void
    {
        $other = $this->root . '/elsewhere/nested';

        $this->publish(self::store(), $other . '/x.html');

        self::assertSame('<html>', file_get_contents($other . '/x.html'));
        self::assertSame([], $this->tempFiles($other));
    }

    public function testFailureBeforePublicationLeavesBothFilesUnchanged(): void
    {
        $this->publish(self::store(['21:30-22:00']));
        $report = $this->root . '/out/report.html';
        $before = [(string) file_get_contents($this->main), (string) file_get_contents($report)];
        file_put_contents($this->root . '/blocker', 'a file, not a directory');

        try {
            $this->publish(self::store(), $this->root . '/blocker/report.html');
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Nothing was written', $e->getMessage());
        }
        self::assertSame($before, [file_get_contents($this->main), file_get_contents($report)]);
        self::assertSame([], $this->tempFiles($this->data));
    }

    public function testFailureAtTheStoreStepKeepsPreviousStoreAndNewReport(): void
    {
        $this->publish(self::store(['21:30-22:00']));
        $before = (string) file_get_contents($this->main);
        mkdir($this->main . '.bak'); // copy() onto a directory fails

        try {
            $this->publish(self::store(), $this->root . '/out/second.html');
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('snapshot store was not updated', $e->getMessage());
        }
        self::assertFileExists($this->root . '/out/second.html');
        self::assertSame($before, file_get_contents($this->main));
        self::assertSame([], $this->tempFiles($this->data));
    }

    public function testReportCannotBeWrittenIntoTheStoreDirectory(): void
    {
        $this->publish(self::store(['21:30-22:00']));
        $before = (string) file_get_contents($this->main);

        try {
            $this->publish(self::store(), $this->root . '/data/./' . SnapshotStore::FILE);
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('holds the snapshot store', $e->getMessage());
        }
        self::assertSame($before, file_get_contents($this->main));
        self::assertFileDoesNotExist($this->main . '.bak');
    }

    public function testMissingMainWithBakStops(): void
    {
        mkdir($this->data);
        file_put_contents($this->main . '.bak', SnapshotStore::encode(self::store()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('restore the .bak');

        SnapshotStore::load($this->data);
    }

    public function testBothFilesMissingGivesAnEmptyStore(): void
    {
        self::assertSame(SnapshotStore::empty(), SnapshotStore::load($this->root . '/nothing-here'));
    }

    public function testEmptyMapsRoundTripAsObjects(): void
    {
        $store = ['version' => 1, 'calendars' => [7 => ['name' => 'Tutor A', 'days' => []]]];

        $json = SnapshotStore::encode($store);

        self::assertStringContainsString('"days": {}', $json);
        self::assertStringContainsString('"calendars": {}', SnapshotStore::encode(SnapshotStore::empty()));
        $this->publish($store);
        self::assertSame($store, SnapshotStore::load($this->data));
    }

    public function testTouchingAndOverlappingIntervalsAreValid(): void
    {
        SnapshotStore::validate(self::store(['21:30-22:00', '22:00-22:30']));
        // Slots of different durations are kept apart, not merged.
        SnapshotStore::validate(self::store(['21:30-22:00', '21:30-22:30', '22:00-22:30']));

        $this->addToAssertionCount(2);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidFiles(): iterable
    {
        $entry = '"observedAt": "2026-10-07T14:32:07+01:00", "hours": ["21:30-22:00"]';
        $wrap = static fn (string $days): string => '{"version": 1, "calendars": {"7": {"name": "A", "days": {' . $days . '}}}}';

        yield 'not JSON' => ['{"version": 1,'];
        yield 'unknown version' => ['{"version": 2, "calendars": {}}'];
        yield 'extra top-level key' => ['{"version": 1, "calendars": {}, "x": 1}'];
        yield 'calendar ID not numeric' => ['{"version": 1, "calendars": {"abc": {"name": "A", "days": {}}}}'];
        yield 'bad date key' => [$wrap('"2026-13-02": {' . $entry . '}')];
        yield 'impossible date' => [$wrap('"2026-02-30": {' . $entry . '}')];
        yield 'bad observedAt' => [$wrap('"2026-11-02": {"observedAt": "yesterday", "hours": []}')];
        yield 'bad interval' => [$wrap('"2026-11-02": {"observedAt": "2026-10-07T14:32:07+01:00", "hours": ["21:30-24:00"]}')];
        yield 'reversed interval' => [$wrap('"2026-11-02": {"observedAt": "2026-10-07T14:32:07+01:00", "hours": ["22:00-21:30"]}')];
        yield 'duplicate intervals' => [$wrap('"2026-11-02": {"observedAt": "2026-10-07T14:32:07+01:00", "hours": ["21:30-22:00", "21:30-22:00"]}')];
        yield 'unordered starts' => [$wrap('"2026-11-02": {"observedAt": "2026-10-07T14:32:07+01:00", "hours": ["22:00-22:30", "21:30-22:00"]}')];
        yield 'unordered ends' => [$wrap('"2026-11-02": {"observedAt": "2026-10-07T14:32:07+01:00", "hours": ["21:30-22:30", "21:30-22:00"]}')];
        yield 'missing hours' => [$wrap('"2026-11-02": {"observedAt": "2026-10-07T14:32:07+01:00"}')];
    }

    #[DataProvider('invalidFiles')]
    public function testInvalidFileFailsNamingBothFiles(string $json): void
    {
        mkdir($this->data);
        file_put_contents($this->main, $json);

        try {
            SnapshotStore::load($this->data);
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertStringContainsString(SnapshotStore::FILE . ' is invalid', $e->getMessage());
            self::assertStringContainsString('.bak', $e->getMessage());
        }
    }

    public function testSecondRunWhileLockedFails(): void
    {
        $lock = SnapshotStore::lock($this->data);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Another run');

            SnapshotStore::lock($this->data);
        } finally {
            fclose($lock);
        }
    }

    public function testLockIsReleasedWhenClosed(): void
    {
        fclose(SnapshotStore::lock($this->data));

        fclose(SnapshotStore::lock($this->data));
        $this->addToAssertionCount(1);
    }
}
