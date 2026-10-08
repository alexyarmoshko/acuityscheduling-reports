<?php

declare(strict_types=1);

namespace AcuityReport;

/**
 * One calendar's slots on one local date.
 *
 * $hoursUnknown: this run could not see the whole date and no snapshot entry
 * exists. $storedAt: the snapshot's observedAt when a stored slot is shown
 * (for --debug-tutor; the report page does not show it).
 */
final class Day
{
    /** @param list<Slot> $slots ordered by start */
    public function __construct(
        public readonly string $date,
        public readonly bool $hoursUnknown,
        public readonly ?string $storedAt,
        public readonly array $slots,
    ) {
    }
}
