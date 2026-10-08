<?php

declare(strict_types=1);

namespace AcuityReport\Tests;

use AcuityReport\Credentials;
use AcuityReport\HttpTransport;
use RuntimeException;

/** Returns queued or routed responses and records requested URLs. */
final class FakeTransport implements HttpTransport
{
    /** @var list<string> */
    public array $urls = [];

    /** @var list<array{status: int, headers: array<string, string>, body: string}> */
    private array $queue = [];

    /** @var array<string, array{status: int, body: mixed}> */
    private array $routes = [];

    public function push(int $status, mixed $body, array $headers = []): self
    {
        $this->queue[] = ['status' => $status, 'headers' => $headers, 'body' => is_string($body) ? $body : json_encode($body)];
        return $this;
    }

    /**
     * Responds to any URL whose path (without query) equals $path. $body is
     * JSON-encoded; a Closure receives the parsed query and returns the body.
     */
    public function route(string $path, mixed $body, int $status = 200): self
    {
        $this->routes[$path] = ['status' => $status, 'body' => $body];
        return $this;
    }

    public function get(string $url, Credentials $credentials): array
    {
        $this->urls[] = $url;
        if ($this->queue !== []) {
            return array_shift($this->queue);
        }
        $path = substr((string) parse_url($url, PHP_URL_PATH), strlen('/api/v1'));
        if (isset($this->routes[$path])) {
            $body = $this->routes[$path]['body'];
            if ($body instanceof \Closure) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $body = $body($query);
            }
            return ['status' => $this->routes[$path]['status'], 'headers' => [], 'body' => (string) json_encode($body)];
        }
        throw new RuntimeException("FakeTransport: no response for {$url}");
    }
}
