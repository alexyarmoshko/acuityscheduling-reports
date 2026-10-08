<?php

declare(strict_types=1);

namespace AcuityReport\Tests;

use AcuityReport\Intervals;
use PHPUnit\Framework\TestCase;

final class IntervalsTest extends TestCase
{
    public function testOverlapsIsHalfOpen(): void
    {
        self::assertFalse(Intervals::overlapsAny([0, 30], [[30, 60]]));
        self::assertTrue(Intervals::overlapsAny([0, 31], [[30, 60]]));
        self::assertTrue(Intervals::overlapsAny([10, 20], [[0, 60]]));
        self::assertFalse(Intervals::overlapsAny([0, 30], []));
    }
}
