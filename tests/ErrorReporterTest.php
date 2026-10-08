<?php

declare(strict_types=1);

namespace AcuityReport\Tests;

use AcuityReport\ErrorReporter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ErrorReporterTest extends TestCase
{
    public function testOwnRuntimeExceptionMessageIsShown(): void
    {
        self::assertSame('Error: settings missing', ErrorReporter::describe(new RuntimeException('settings missing')));
    }

    public function testUnexpectedErrorShowsClassAndLocationOnly(): void
    {
        $text = ErrorReporter::describe(new \TypeError('value DUMMY-SECRET-123 is wrong'));

        self::assertStringStartsWith('Unexpected error: TypeError at ErrorReporterTest.php:', $text);
        self::assertStringNotContainsString('DUMMY', $text);
        self::assertStringNotContainsString("\n", $text);
    }

    public function testJsonExceptionIsTreatedAsUnexpected(): void
    {
        $text = ErrorReporter::describe(new \JsonException('Malformed DUMMY-SECRET'));

        self::assertStringNotContainsString('DUMMY', $text);
    }
}
