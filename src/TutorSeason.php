<?php

declare(strict_types=1);

namespace AcuityReport;

final class TutorSeason
{
    /** @param array<string, Day> $days every date of the season, in order, keyed YYYY-MM-DD */
    public function __construct(
        public readonly Tutor $tutor,
        public readonly array $days,
    ) {
    }
}
