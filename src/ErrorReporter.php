<?php

declare(strict_types=1);

namespace AcuityReport;

use RuntimeException;
use Throwable;

/** Turns an exception into a one-line message that is safe to print. */
final class ErrorReporter
{
    public static function describe(Throwable $e): string
    {
        // Our own RuntimeExceptions carry messages written to be printable.
        if ($e instanceof RuntimeException) {
            return 'Error: ' . $e->getMessage();
        }

        // Anything else (TypeError, JsonException, ...) is unexpected, and its
        // message can quote data values. Report the class and location only.
        return 'Unexpected error: ' . $e::class . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
    }
}
