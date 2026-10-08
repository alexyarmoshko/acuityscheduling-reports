<?php

declare(strict_types=1);

namespace AcuityReport;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The season: an inclusive range of months, as local dates in one time zone.
 * Retrieval builds it from config.php, the page from the saved lastRun.
 */
final class Season
{
    private readonly DateTimeImmutable $start;
    private readonly DateTimeImmutable $end;

    /** @param string $startMonth YYYY-MM, as is $endMonth (inclusive) */
    public function __construct(string $startMonth, string $endMonth, DateTimeZone $tz)
    {
        $this->start = new DateTimeImmutable($startMonth . '-01 00:00', $tz);
        $this->end = (new DateTimeImmutable($endMonth . '-01 00:00', $tz))->modify('first day of next month');
    }

    /** The first instant after the season. */
    public function end(): DateTimeImmutable
    {
        return $this->end;
    }

    /** @return list<string> every local date, YYYY-MM-DD */
    public function dates(): array
    {
        $dates = [];
        // modify() keeps local midnight, so 23- and 25-hour dates step right.
        for ($day = $this->start; $day < $this->end; $day = $day->modify('+1 day')) {
            $dates[] = $day->format('Y-m-d');
        }

        return $dates;
    }

    /** @return list<string> YYYY-MM */
    public function months(): array
    {
        $months = [];
        for ($month = $this->start; $month < $this->end; $month = $month->modify('first day of next month')) {
            $months[] = $month->format('Y-m');
        }

        return $months;
    }
}
