<?php

declare(strict_types=1);

namespace AcuityReport;

/** Sends one authenticated GET request. Replaced by a fake in tests. */
interface HttpTransport
{
    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     *         Header names are lower-case.
     * @throws \RuntimeException on a network-level failure (no HTTP response)
     */
    public function get(string $url, Credentials $credentials): array;
}
