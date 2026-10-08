<?php

declare(strict_types=1);

namespace AcuityReport\Tests;

use AcuityReport\Credentials;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CredentialsTest extends TestCase
{
    private const KEY = 'DUMMY-API-KEY-abc123';

    public function testNoDumpOrCastShowsEitherCredential(): void
    {
        $credentials = new Credentials('98765', self::KEY);
        ob_start();
        var_dump($credentials);
        $outputs = [
            'var_dump' => (string) ob_get_clean(),
            'print_r' => print_r($credentials, true),
            'var_export' => var_export($credentials, true),
            'json_encode' => (string) json_encode($credentials),
            'array cast' => print_r((array) $credentials, true),
        ];

        foreach ($outputs as $how => $text) {
            self::assertStringNotContainsString(self::KEY, $text, $how);
            self::assertStringNotContainsString('98765', $text, $how);
        }
    }

    public function testCannotBeCloned(): void
    {
        $this->expectException(\Error::class);

        $credentials = new Credentials('12345', self::KEY);
        $clone = clone $credentials;
    }

    public function testCannotBeSerialized(): void
    {
        $this->expectException(RuntimeException::class);

        serialize(new Credentials('12345', self::KEY));
    }

    public function testTraceHidesBothArguments(): void
    {
        try {
            // An empty key throws inside the constructor, whose frame records
            // both arguments.
            new Credentials('98765', '');
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertStringNotContainsString('98765', $e->getTraceAsString());
            self::assertStringNotContainsString('98765', print_r($e->getTrace()[0]['args'] ?? [], true));
        }
        try {
            new Credentials('not-a-number', self::KEY);
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertStringNotContainsString(self::KEY, $e->getTraceAsString());
            self::assertStringNotContainsString(self::KEY, print_r($e->getTrace()[0]['args'] ?? [], true));
        }
    }

    public function testRedact(): void
    {
        $credentials = new Credentials('98765', self::KEY);

        self::assertSame('id [redacted] key [redacted]', $credentials->redact('id 98765 key ' . self::KEY));
    }

    public function testFromEnvRequiresBothKeys(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ACUITY_API_KEY is missing');

        Credentials::fromEnv(['ACUITY_USER_ID' => '12345']);
    }

    public function testBasicAuth(): void
    {
        self::assertSame('12345:' . self::KEY, (new Credentials('12345', self::KEY))->basicAuth());
    }
}
