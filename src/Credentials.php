<?php

declare(strict_types=1);

namespace AcuityReport;

use RuntimeException;
use WeakMap;

/**
 * Acuity API credentials.
 *
 * The values are held in a private static WeakMap keyed by this object, not
 * in instance properties. var_dump, print_r, var_export, json_encode and
 * (array) casts only show instance properties, so no dump of this object or
 * of anything holding it shows them. (A closure would not do: print_r of an
 * (array) cast shows a closure's captured variables.) Constructor arguments
 * are marked sensitive, so stack traces do not show them either.
 */
final class Credentials
{
    /** @var WeakMap<self, array{string, string}>|null */
    private static ?WeakMap $secrets = null;

    public function __construct(
        #[\SensitiveParameter] string $userId,
        #[\SensitiveParameter] string $apiKey,
    ) {
        if (preg_match('/\A\d+\z/', $userId) !== 1) {
            throw new RuntimeException('ACUITY_USER_ID must be numeric');
        }
        if ($apiKey === '') {
            throw new RuntimeException('ACUITY_API_KEY is empty');
        }
        self::$secrets ??= new WeakMap();
        self::$secrets[$this] = [$userId, $apiKey];
    }

    /** @param array<string, string> $env */
    public static function fromEnv(#[\SensitiveParameter] array $env): self
    {
        foreach (['ACUITY_USER_ID', 'ACUITY_API_KEY'] as $key) {
            if (($env[$key] ?? '') === '') {
                throw new RuntimeException("{$key} is missing from .env");
            }
        }

        return new self($env['ACUITY_USER_ID'], $env['ACUITY_API_KEY']);
    }

    /** "user:key" for HTTP Basic auth. */
    public function basicAuth(): string
    {
        [$userId, $apiKey] = self::$secrets[$this];

        return $userId . ':' . $apiKey;
    }

    /** Replaces any occurrence of either credential in $text. */
    public function redact(string $text): string
    {
        [$userId, $apiKey] = self::$secrets[$this];

        return str_replace([$apiKey, $userId], '[redacted]', $text);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['credentials' => '[redacted]'];
    }

    public function __serialize(): array
    {
        throw new RuntimeException('Credentials cannot be serialized');
    }

    /** A clone would have no WeakMap entry, so cloning is not allowed. */
    private function __clone()
    {
    }
}
