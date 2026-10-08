<?php

declare(strict_types=1);

namespace AcuityReport;

use DateTimeImmutable;

/**
 * One configured slot on one local date, never merged with others. Times are
 * in Europe/London. Status and source are kept for --debug-tutor; the report
 * page shows the time only.
 */
final class Slot
{
    public const FREE = 'free';
    public const BOOKED = 'booked';

    public const LIVE = 'live';
    public const STORED = 'stored';

    public function __construct(
        public readonly DateTimeImmutable $start,
        public readonly DateTimeImmutable $end,
        public readonly string $status,
        public readonly string $source,
    ) {
    }
}
