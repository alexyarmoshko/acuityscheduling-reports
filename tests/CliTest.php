<?php

declare(strict_types=1);

namespace AcuityReport\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Runs acuity-report.php as a separate process. */
final class CliTest extends TestCase
{
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

    public function testEmptyOutValueIsRejected(): void
    {
        [$code] = self::runScript('--out=');

        self::assertSame(2, $code);
    }
}
