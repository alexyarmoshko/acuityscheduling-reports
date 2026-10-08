<?php

declare(strict_types=1);

namespace AcuityReport;

final class TutorSeason
{
    /**
     * @param array<string, Day> $days every date of the season, in order, keyed YYYY-MM-DD
     * @param list<array{id: int, name: string, duration: int}> $types the listed types this calendar offers
     */
    public function __construct(
        public readonly Tutor $tutor,
        public readonly array $days,
        public readonly array $types = [],
    ) {
    }
}
