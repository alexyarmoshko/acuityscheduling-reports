<?php

declare(strict_types=1);

namespace AcuityReport;

/**
 * One calendar's slots on one local date.
 *
 * At most one flag applies: $hoursUnknown when part of the session window
 * could not be read and no snapshot covers it; $asOf (the snapshot's
 * observedAt) when stored hours decide that part.
 */
final class Day
{
    /** @param list<Slot> $slots ordered by start */
    public function __construct(
        public readonly string $date,
        public readonly bool $hoursUnknown,
        public readonly ?string $asOf,
        public readonly array $slots,
    ) {
    }
}
