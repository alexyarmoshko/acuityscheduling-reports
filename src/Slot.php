<?php

declare(strict_types=1);

namespace AcuityReport;

use DateTimeImmutable;

/**
 * A booked session, or a range of merged free slots, on one local date.
 * Times are in Europe/London.
 */
final class Slot
{
    public const FREE = 'free';
    public const BOOKED = 'booked';

    public const LIVE = 'live';
    public const STORED = 'stored';

    /**
     * @param array<string, string> $starts offered start times (HH:MM, in order)
     *        => source: Slot::LIVE (from this run) or Slot::STORED (snapshot store)
     */
    public function __construct(
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $end,
        public readonly string $status,
        public readonly array $starts,
        public readonly ?string $typeName = null,
    ) {
    }
}
