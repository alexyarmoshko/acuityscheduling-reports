<?php

declare(strict_types=1);

namespace AcuityReport;

use Closure;
use DateTimeImmutable;
use JsonException;
use RuntimeException;

/**
 * Read-only Acuity Scheduling API v1 client.
 *
 * GET is the only operation it offers. Requests are throttled below Acuity's
 * documented limit of 10 per second. 429/5xx responses and network errors are
 * retried.
 */
final class AcuityClient
{
    private const BASE_URL = 'https://acuityscheduling.com/api/v1';
    private const MIN_INTERVAL = 0.125; // seconds: at most 8 requests per second
    private const RETRIES = 3;
    private const MAX_RETRY_WAIT = 60.0;

    private ?float $lastRequestAt = null;
    private int $requestCount = 0;

    /**
     * @param (Closure(): float)|null $clock monotonic seconds, for pacing
     * @param (Closure(float): void)|null $sleep
     * @param (Closure(): int)|null $wallClock Unix time, for HTTP-date Retry-After
     */
    public function __construct(
        private readonly Credentials $credentials,
        private readonly HttpTransport $transport = new CurlTransport(),
        private ?Closure $clock = null,
        private ?Closure $sleep = null,
        private ?Closure $wallClock = null,
    ) {
        $this->clock ??= static fn (): float => hrtime(true) / 1e9;
        $this->sleep ??= static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
        $this->wallClock ??= static fn (): int => time();
    }

    /**
     * @param array<string, scalar> $query
     * @return array<mixed> the decoded JSON body (an array or object)
     */
    public function get(string $path, array $query = []): array
    {
        return $this->fetch($path, $query, false);
    }

    /**
     * Like get(), but the response must be a JSON array (list) at its root.
     *
     * @param array<string, scalar> $query
     * @return list<mixed>
     */
    public function getList(string $path, array $query = []): array
    {
        /** @var list<mixed> */
        return $this->fetch($path, $query, true);
    }

    public function requestCount(): int
    {
        return $this->requestCount;
    }

    /**
     * @param array<string, scalar> $query
     * @return array<mixed>
     */
    private function fetch(string $path, array $query, bool $expectList): array
    {
        if (preg_match('#\A(/[a-z][a-z-]*)+\z#', $path) !== 1) {
            throw new RuntimeException('Invalid API path (not shown)');
        }

        $url = self::BASE_URL . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        for ($attempt = 0; ; $attempt++) {
            $this->pace();
            $this->requestCount++;
            try {
                $response = $this->transport->get($url, $this->credentials);
            } catch (RuntimeException $e) {
                if ($attempt < self::RETRIES) {
                    ($this->sleep)($this->backoff($attempt));
                    continue;
                }
                throw new RuntimeException(
                    "GET {$path} failed after " . self::RETRIES . ' retries: ' . $this->credentials->redact($e->getMessage()),
                );
            }
            $status = $response['status'];

            if ($status >= 200 && $status < 300) {
                return $this->decode($path, $response['body'], $expectList);
            }

            $retryable = $status === 429 || $status >= 500;
            if ($retryable && $attempt < self::RETRIES) {
                $wait = $this->retryDelay($attempt, $response['headers']['retry-after'] ?? null);
                if ($wait > self::MAX_RETRY_WAIT) {
                    throw new RuntimeException(sprintf(
                        'GET %s failed: HTTP %d, and the server asked to wait %d s; try again later',
                        $path,
                        $status,
                        (int) ceil($wait),
                    ));
                }
                ($this->sleep)($wait);
                continue;
            }

            throw new RuntimeException($this->errorMessage($path, $status, $retryable));
        }
    }


    private function pace(): void
    {
        $now = ($this->clock)();
        if ($this->lastRequestAt !== null) {
            $wait = $this->lastRequestAt + self::MIN_INTERVAL - $now;
            if ($wait > 0) {
                ($this->sleep)($wait);
                $now += $wait;
            }
        }
        $this->lastRequestAt = $now;
    }

    /** Seconds to wait before retrying; may exceed MAX_RETRY_WAIT. */
    private function retryDelay(int $attempt, ?string $retryAfter): float
    {
        if ($retryAfter !== null) {
            if (preg_match('/\A\d+\z/', $retryAfter) === 1) {
                return (float) $retryAfter;
            }
            // HTTP-date form, e.g. "Wed, 21 Oct 2026 07:28:00 GMT".
            $date = DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $retryAfter, new \DateTimeZone('UTC'));
            if ($date !== false) {
                return max(0.0, (float) ($date->getTimestamp() - ($this->wallClock)()));
            }
        }

        return $this->backoff($attempt);
    }

    private function backoff(int $attempt): float
    {
        return (float) (2 ** $attempt); // 1, 2, 4 seconds
    }

    /** @return array<mixed> */
    private function decode(string $path, #[\SensitiveParameter] string $body, bool $expectList): array
    {
        // Checked on the raw text: associative decoding cannot tell `{}` or
        // `{"0": ...}` from a list.
        if ($expectList && !str_starts_with(ltrim($body), '[')) {
            throw new RuntimeException("GET {$path}: expected a JSON list");
        }
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException("GET {$path}: response is not valid JSON");
        }
        if (!is_array($data)) {
            throw new RuntimeException("GET {$path}: expected a JSON array or object");
        }

        return $data;
    }

    private function errorMessage(string $path, int $status, bool $retried): string
    {
        $message = "GET {$path} failed: HTTP {$status}";
        if ($retried) {
            $message .= ' after ' . self::RETRIES . ' retries';
        }
        if ($status === 401) {
            return $message . ' (check ACUITY_USER_ID and ACUITY_API_KEY in .env)';
        }

        // Nothing from the response body is printed: any server-provided text,
        // even an identifier-shaped error code, could echo the credentials in a
        // form redaction cannot catch (a hex key, base64 Basic auth). Use
        // --spike or the Acuity UI to investigate a failing request.
        return $message;
    }
}
