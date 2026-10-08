<?php

declare(strict_types=1);

namespace AcuityReport;

use DateTimeImmutable;

/** Everything the report page shows. */
final class Report
{
    /**
     * @param list<array{id: int, name: string, duration: int}> $types
     * @param list<TutorSeason> $tutors
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly string $seasonStart,
        public readonly string $seasonEnd,
        public readonly DateTimeImmutable $generatedAt,
        public readonly string $timezone,
        public readonly array $types,
        public readonly DateTimeImmutable $bookableFrom,
        public readonly DateTimeImmutable $bookableUntil,
        public readonly array $tutors,
        public readonly array $warnings,
    ) {
    }
}
