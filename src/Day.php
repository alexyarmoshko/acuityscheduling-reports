<?php

declare(strict_types=1);

namespace AcuityReport;

/**
 * One calendar's configured slots on one local date, as saved by this run.
 *
 * $hoursUnknown: no run has seen the whole date yet, so further hours may be
 * missing. $storedAt: the saved entry's observedAt when a saved slot is shown
 * (for --debug-tutor).
 */
final class Day
{
    /** @param list<Slot> $slots ordered by start, then end */
    public function __construct(
        public readonly string $date,
        public readonly bool $hoursUnknown,
        public readonly ?string $storedAt,
        public readonly array $slots,
    ) {
    }
}
