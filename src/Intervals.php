<?php

declare(strict_types=1);

namespace AcuityReport;

/**
 * Operations on half-open [start, end) intervals of Unix timestamps, given as
 * two-element lists.
 */
final class Intervals
{
    /**
     * @param array{int, int} $interval
     * @param list<array{int, int}> $others
     */
    public static function overlapsAny(array $interval, array $others): bool
    {
        foreach ($others as [$start, $end]) {
            if ($interval[0] < $end && $start < $interval[1]) {
                return true;
            }
        }

        return false;
    }
}
