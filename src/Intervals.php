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
     * Sorted, with overlapping intervals merged. Touching intervals
     * ([a, b) and [b, c)) are kept apart.
     *
     * @param list<array{int, int}> $intervals
     * @return list<array{int, int}>
     */
    public static function normalize(array $intervals): array
    {
        $intervals = self::sorted($intervals);
        $out = [];
        foreach ($intervals as [$start, $end]) {
            $last = count($out) - 1;
            if ($last >= 0 && $start < $out[$last][1]) {
                $out[$last][1] = max($out[$last][1], $end);
            } else {
                $out[] = [$start, $end];
            }
        }

        return $out;
    }

    /**
     * The parts of $base not covered by any interval in $remove.
     *
     * @param list<array{int, int}> $base
     * @param list<array{int, int}> $remove
     * @return list<array{int, int}>
     */
    public static function subtract(array $base, array $remove): array
    {
        $remove = self::sorted($remove);
        $out = [];
        foreach ($base as [$start, $end]) {
            $cursor = $start;
            foreach ($remove as [$removeStart, $removeEnd]) {
                if ($removeEnd <= $cursor || $removeStart >= $end) {
                    continue;
                }
                if ($removeStart > $cursor) {
                    $out[] = [$cursor, $removeStart];
                }
                $cursor = max($cursor, $removeEnd);
                if ($cursor >= $end) {
                    break;
                }
            }
            if ($cursor < $end) {
                $out[] = [$cursor, $end];
            }
        }

        return $out;
    }

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

    /**
     * @param list<array{int, int}> $intervals
     * @return list<array{int, int}>
     */
    private static function sorted(array $intervals): array
    {
        usort($intervals, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $intervals;
    }
}
