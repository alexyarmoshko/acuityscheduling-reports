<?php

declare(strict_types=1);

namespace AcuityReport\Tests;

use AcuityReport\Env;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EnvTest extends TestCase
{
    public function testKeepsCommentAndSeparatorCharactersInsideValues(): void
    {
        $env = Env::parse("KEY=abc#def;ghi=jkl\n");

        self::assertSame(['KEY' => 'abc#def;ghi=jkl'], $env);
    }

    public function testSplitsOnFirstEqualsAndTrims(): void
    {
        $env = Env::parse("  ACUITY_USER_ID =  12345  \nACUITY_API_KEY=a=b\n");

        self::assertSame(['ACUITY_USER_ID' => '12345', 'ACUITY_API_KEY' => 'a=b'], $env);
    }

    public function testStripsOneMatchingPairOfQuotes(): void
    {
        $env = Env::parse("A=\"quoted\"\nB='single'\nC=\"\"nested\"\"\nD=\"unbalanced\nE='mixed\"\n");

        self::assertSame([
            'A' => 'quoted',
            'B' => 'single',
            'C' => '"nested"',
            'D' => '"unbalanced',
            'E' => '\'mixed"',
        ], $env);
    }

    public function testSkipsBlankLinesAndFullLineComments(): void
    {
        $env = Env::parse("# comment\n\n   # indented comment\r\nKEY=value\r\n");

        self::assertSame(['KEY' => 'value'], $env);
    }

    public function testDoesNotCastValues(): void
    {
        $env = Env::parse("A=no\nB=false\nC=null\nD=\n");

        self::assertSame(['A' => 'no', 'B' => 'false', 'C' => 'null', 'D' => ''], $env);
    }

    public function testStripsByteOrderMark(): void
    {
        $env = Env::parse("\u{FEFF}KEY=value\n");

        self::assertSame(['KEY' => 'value'], $env);
    }

    public function testMalformedLineErrorDoesNotEchoContents(): void
    {
        try {
            Env::parse("KEY=value\nsecret-without-equals\n");
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('line 2', $e->getMessage());
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function testRejectsEmptyKey(): void
    {
        $this->expectException(RuntimeException::class);

        Env::parse("=value\n");
    }

    public function testRejectsUtf16(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UTF-8');

        Env::parse("\xFF\xFE" . mb_convert_encoding("KEY=value\n", 'UTF-16LE', 'UTF-8'));
    }

    public function testLoadErrorCarriesNoFileContents(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'env');
        self::assertIsString($path);
        file_put_contents($path, "DUMMY-SECRET-WITHOUT-EQUALS\n");

        try {
            Env::load($path);
            self::fail('Expected exception');
        } catch (RuntimeException $e) {
            self::assertNull($e->getPrevious());
            self::assertStringNotContainsString('DUMMY', $e->getMessage());
            foreach ($e->getTrace() as $frame) {
                self::assertNotSame('parse', $frame['function']);
            }
        } finally {
            unlink($path);
        }
    }

    public function testLoadFailsForMissingFile(): void
    {
        $this->expectException(RuntimeException::class);

        Env::load(__DIR__ . '/does-not-exist.env');
    }
}
