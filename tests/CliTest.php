<?php

declare(strict_types=1);

namespace AcuityReport\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Runs acuity-report.php as a separate process. */
final class CliTest extends TestCase
{
    use RemovesTempDirs;

    /** @return array{int, string, string} exit code, stdout, stderr */
    private static function runScript(string ...$args): array
    {
        $command = array_merge([PHP_BINARY, __DIR__ . '/../acuity-report.php'], $args);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $stdout, (string) $stderr];
    }

    public function testHelpPrintsUsageAndSucceeds(): void
    {
        [$code, $stdout] = self::runScript('--help');

        self::assertSame(0, $code);
        self::assertStringContainsString('Usage: php acuity-report.php', $stdout);
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedArguments(): iterable
    {
        yield 'positional' => ['DUMMY-SECRET-123'];
        yield 'unknown option with value' => ['--api-key=DUMMY-SECRET-123'];
        yield 'flag given a value' => ['--help=DUMMY-SECRET-123'];
        yield 'single dash' => ['-DUMMY-SECRET-123'];
        yield 'unknown lowercase name' => ['--dummy-secret'];
        yield 'known valued option without value' => ['--out'];
        yield 'non-numeric calendar ID' => ['--debug-tutor=DUMMY-SECRET-123'];
    }

    #[DataProvider('rejectedArguments')]
    public function testRejectedArgumentIsNotEchoed(string $arg): void
    {
        [$code, $stdout, $stderr] = self::runScript($arg);

        self::assertSame(2, $code);
        self::assertStringNotContainsStringIgnoringCase('dummy', $stdout . $stderr);
    }

    public function testRenderOnlyNeedsNoEnvFile(): void
    {
        // A copy of the script in a folder with config.php and saved data, but no .env.
        $dir = sys_get_temp_dir() . '/cli-render-' . bin2hex(random_bytes(4));
        mkdir($dir . '/data', 0777, true);
        mkdir($dir . '/src');
        mkdir($dir . '/templates');
        copy(__DIR__ . '/../acuity-report.php', $dir . '/acuity-report.php');
        foreach (glob(__DIR__ . '/../src/*.php') ?: [] as $file) {
            copy($file, $dir . '/src/' . basename($file));
        }
        copy(__DIR__ . '/../templates/report.php', $dir . '/templates/report.php');
        file_put_contents($dir . '/config.php', "<?php return ['season_start' => '2026-11', 'season_end' => '2027-03',"
            . " 'appointment_type_ids' => [42], 'min_notice_hours' => 18, 'max_days_ahead' => 287];");
        file_put_contents($dir . '/data/availability-cache.json', json_encode(['version' => 1, 'lastRun' => [
            'generatedAt' => '2026-10-08T14:32:07+01:00', 'seasonStart' => '2026-11', 'seasonEnd' => '2027-03',
            'types' => [], 'bookableFrom' => '2026-10-09T09:32:07+01:00', 'bookableUntil' => '2027-07-21T15:09:07+01:00', 'warnings' => [],
        ], 'calendars' => ['7' => ['name' => 'Tutor A', 'days' => new \stdClass()]]]));

        try {
            $process = proc_open([PHP_BINARY, $dir . '/acuity-report.php', '--render-only', '--out=' . $dir . '/r.html'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            self::assertSame(0, proc_close($process), $stderr);
            self::assertStringContainsString('Report written to', $stdout);
            self::assertFileExists($dir . '/r.html');
            self::assertFileDoesNotExist($dir . '/.env');
        } finally {
            $this->remove($dir);
        }
    }

    public function testEmptyOutValueIsRejected(): void
    {
        [$code] = self::runScript('--out=');

        self::assertSame(2, $code);
    }
}
