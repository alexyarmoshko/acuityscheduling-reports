<?php

declare(strict_types=1);

namespace AcuityReport;

/** A calendar in the report. Only the ID and name are kept from the API. */
final class Tutor
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {
    }
}
