<?php

declare(strict_types=1);

namespace AcuityReport;

use DateTimeImmutable;

/**
 * What one run built: the reported calendars' days, for --debug-tutor and the
 * console. The page itself is rendered from the saved store (plan step 5).
 */
final class Report
{
    /**
     * @param list<TutorSeason> $tutors
     * @param list<string> $warnings
     * @param list<array{id: int, name: string}> $removed withdrawn calendars removed from the store
     */
    public function __construct(
        public readonly string $timezone,
        public readonly DateTimeImmutable $bookableFrom,
        public readonly DateTimeImmutable $bookableUntil,
        public readonly array $tutors,
        public readonly array $warnings,
        public readonly array $removed = [],
    ) {
    }
}
