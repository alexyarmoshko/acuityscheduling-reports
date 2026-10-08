<?php

declare(strict_types=1);

namespace AcuityReport\Tests;

use AcuityReport\AcuityClient;
use AcuityReport\Credentials;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AcuityClientTest extends TestCase
{
    private const KEY = 'DUMMY-API-KEY-abc123';

    private FakeTransport $transport;
    private float $now = 1000.0;
    /** @var list<float> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
    }

    private function client(): AcuityClient
    {
        return new AcuityClient(
            new Credentials('12345', self::KEY),
            $this->transport,
            fn (): float => $this->now,
            function (float $s): void {
                $this->sleeps[] = $s;
                $this->now += $s;
            },
        );
    }

    public function testBuildsUrlWithEncodedQuery(): void
    {
        $this->transport->push(200, [['date' => '2026-11-02']]);

        $data = $this->client()->getList('/availability/dates', ['month' => '2026-11', 'timezone' => 'Europe/London']);

        self::assertSame([['date' => '2026-11-02']], $data);
        self::assertSame(
            'https://acuityscheduling.com/api/v1/availability/dates?month=2026-11&timezone=Europe%2FLondon',
            $this->transport->urls[0],
        );
    }

    public function testOffersGetOnly(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(AcuityClient::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        sort($methods);

        self::assertSame(['__construct', 'getList', 'requestCount'], $methods);
    }

    public function testRejectsInvalidPathWithoutRequest(): void
    {
        $this->transport->push(200, []);

        try {
            $this->client()->getList('https://example.com/' . self::KEY);
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertSame('Invalid API path (not shown)', $e->getMessage());
        }
        self::assertSame([], $this->transport->urls);
    }

    public function testPacesRequestsToEightPerSecond(): void
    {
        $this->transport->push(200, [])->push(200, [])->push(200, []);
        $client = $this->client();

        $client->getList('/calendars');
        $client->getList('/calendars');
        $this->now += 1.0; // a slow caller needs no pause
        $client->getList('/calendars');

        self::assertEqualsWithDelta([0.125], $this->sleeps, 1e-9);
    }

    public function testRetries429HonouringRetryAfter(): void
    {
        $this->transport->push(429, '', ['retry-after' => '3'])->push(200, [['ok' => true]]);

        self::assertSame([['ok' => true]], $this->client()->getList('/calendars'));
        self::assertContains(3.0, $this->sleeps);
        self::assertCount(2, $this->transport->urls);
    }

    public function testLongRetryAfterStopsWithoutRetryingEarly(): void
    {
        $this->transport->push(429, '', ['retry-after' => '3600'])->push(200, []);

        try {
            $this->client()->getList('/calendars');
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('asked to wait 3600 s', $e->getMessage());
        }
        self::assertCount(1, $this->transport->urls);
    }

    public function testHttpDateRetryAfterIsHonoured(): void
    {
        $this->transport->push(503, '', ['retry-after' => 'Thu, 08 Oct 2026 12:00:05 GMT'])->push(200, []);
        $client = new AcuityClient(
            new Credentials('12345', self::KEY),
            $this->transport,
            fn (): float => $this->now,
            function (float $s): void {
                $this->sleeps[] = $s;
            },
            static fn (): int => (new \DateTimeImmutable('2026-10-08 12:00:00 UTC'))->getTimestamp(),
        );

        $client->getList('/calendars');

        self::assertContains(5.0, $this->sleeps);
    }

    public function testRetriesNetworkErrors(): void
    {
        $transport = new class implements \AcuityReport\HttpTransport {
            public int $calls = 0;
            public function get(string $url, Credentials $credentials): array
            {
                if (++$this->calls < 3) {
                    throw new RuntimeException('Network error (curl 28): timed out');
                }
                return ['status' => 200, 'headers' => [], 'body' => '[]'];
            }
        };
        $client = new AcuityClient(new Credentials('12345', self::KEY), $transport, fn (): float => $this->now, function (float $s): void {
            $this->sleeps[] = $s;
        });

        self::assertSame([], $client->getList('/calendars'));
        self::assertSame(3, $transport->calls);
    }

    public function testServerMessageNeverPrintedEvenWhenEncoded(): void
    {
        $encoded = base64_encode('12345:' . self::KEY);
        $this->transport->push(400, ['message' => "Bad auth: Basic {$encoded} for 12345 " . self::KEY]);

        try {
            $this->client()->getList('/calendars');
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertStringNotContainsString($encoded, $e->getMessage());
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
            self::assertStringNotContainsString('12345', $e->getMessage());
        }
    }

    public function testResponseBodyIsHiddenInTraces(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $this->transport->push(200, '[not json ' . self::KEY);
            try {
                $this->client()->getList('/calendars');
                self::fail('Expected exception');
            } catch (RuntimeException $e) {
                foreach ($e->getTrace() as $frame) {
                    if (($frame['class'] ?? '') === AcuityClient::class) {
                        self::assertStringNotContainsString(self::KEY, print_r($frame['args'] ?? [], true), $frame['function']);
                    }
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function nonListBodies(): iterable
    {
        yield 'empty object' => ['{}'];
        yield 'object with numeric key' => ['{"0":{"id":7}}'];
        yield 'object' => ['{"a":1}'];
        yield 'scalar' => ['"text"'];
        yield 'html' => ['<html>maintenance</html>'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonListBodies')]
    public function testGetListRejectsObjects(string $body): void
    {
        $this->transport->push(200, $body);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expected a JSON list');

        $this->client()->getList('/calendars');
    }

    public function testGetListAcceptsList(): void
    {
        $this->transport->push(200, ' [{"id":7}]');

        self::assertSame([['id' => 7]], $this->client()->getList('/calendars'));
    }

    public function testGivesUpAfterThreeRetriesOn5xx(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->transport->push(503, 'unavailable');
        }

        try {
            $this->client()->getList('/calendars');
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('HTTP 503 after 3 retries', $e->getMessage());
        }
        self::assertCount(4, $this->transport->urls);
        self::assertSame([1.0, 2.0, 4.0], array_values(array_filter($this->sleeps, static fn ($s) => $s >= 1)));
    }

    public function testDoesNotRetry4xxAndPrintsNoServerText(): void
    {
        $this->transport->push(400, ['status_code' => 400, 'error' => 'invalid_appointment_type', 'message' => 'Appointment type ID is invalid']);

        try {
            $this->client()->getList('/availability/dates', ['appointmentTypeID' => 1]);
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertSame('GET /availability/dates failed: HTTP 400', $e->getMessage());
        }
        self::assertCount(1, $this->transport->urls);
    }

    public function testIdentifierShapedErrorCodeIsNotPrinted(): void
    {
        $this->transport->push(400, ['error' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90']);

        try {
            $this->client()->getList('/calendars');
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertSame('GET /calendars failed: HTTP 400', $e->getMessage());
        }
    }

    public function testUnauthorisedMessageNeverContainsKey(): void
    {
        $this->transport->push(401, ['message' => 'Unauthorized ' . self::KEY]);

        try {
            $this->client()->getList('/calendars');
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('HTTP 401', $e->getMessage());
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
            self::assertStringNotContainsString(self::KEY, $e->getTraceAsString());
        }
    }

    public function testInvalidJsonFails(): void
    {
        $this->transport->push(200, '[<html>maintenance</html>');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not valid JSON');

        $this->client()->getList('/calendars');
    }
}
