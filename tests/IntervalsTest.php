<?php

declare(strict_types=1);

namespace AcuityReport\Tests;

use AcuityReport\Intervals;
use PHPUnit\Framework\TestCase;

final class IntervalsTest extends TestCase
{
    public function testNormalizeMergesOverlapsAndKeepsTouchingApart(): void
    {
        self::assertSame(
            [[0, 30], [30, 75], [100, 110]],
            Intervals::normalize([[100, 110], [30, 60], [0, 30], [45, 75]]),
        );
    }

    public function testSubtract(): void
    {
        self::assertSame([[0, 10], [20, 25], [40, 50]], Intervals::subtract([[0, 50]], [[30, 40], [10, 20], [25, 35]]));
        self::assertSame([], Intervals::subtract([[0, 50]], [[0, 60]]));
        self::assertSame([[0, 50]], Intervals::subtract([[0, 50]], [[50, 60]]));
    }

    public function testOverlapsIsHalfOpen(): void
    {
        self::assertFalse(Intervals::overlapsAny([0, 30], [[30, 60]]));
        self::assertTrue(Intervals::overlapsAny([0, 31], [[30, 60]]));
        self::assertFalse(Intervals::overlapsAny([0, 30], []));
    }
}
