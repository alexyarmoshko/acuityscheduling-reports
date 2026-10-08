<?php

declare(strict_types=1);

namespace AcuityReport\Tests;

use AcuityReport\AcuityClient;
use AcuityReport\Credentials;
use AcuityReport\ListCommand;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CommandsTest extends TestCase
{
    private FakeTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
    }

    private function client(): AcuityClient
    {
        return new AcuityClient(new Credentials('1', 'k'), $this->transport, static fn (): float => 0.0, static function (float $s): void {
        });
    }

    public function testListShowsIdsAndTypeFieldsButNoEmails(): void
    {
        $this->transport
            ->route('/calendars', [['id' => 7, 'name' => "Tutor\nOne", 'email' => 'tutor@example.com']])
            ->route('/appointment-types', [[
                'id' => 42, 'name' => 'Lesson', 'duration' => 30, 'paddingBefore' => 0, 'paddingAfter' => 0,
                'active' => true, 'private' => false, 'type' => 'service', 'calendarIDs' => [7, 8],
            ]]);

        $out = ListCommand::run($this->client());

        self::assertStringContainsString('Tutor One', $out);
        self::assertMatchesRegularExpression('/^42\s+Lesson\s+30\s+0\s+0\s+yes\s+no\s+service\s+7,8$/m', $out);
        self::assertStringNotContainsString('example.com', $out);
    }

    public function testListFailsOnUnexpectedShape(): void
    {
        $this->transport->route('/calendars', [['name' => 'no id']])->route('/appointment-types', []);

        $this->expectException(RuntimeException::class);

        ListCommand::run($this->client());
    }
}
