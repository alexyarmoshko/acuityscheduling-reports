<?php

declare(strict_types=1);

namespace AcuityReport;

use RuntimeException;

// Stack traces must never show argument values: they could include credentials.
ini_set('zend.exception_ignore_args', '1');
date_default_timezone_set('Europe/London');

require_once __DIR__ . '/src/Env.php';
require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/Credentials.php';
require_once __DIR__ . '/src/HttpTransport.php';
require_once __DIR__ . '/src/CurlTransport.php';
require_once __DIR__ . '/src/AcuityClient.php';
require_once __DIR__ . '/src/ListCommand.php';
require_once __DIR__ . '/src/Intervals.php';
require_once __DIR__ . '/src/Tutor.php';
require_once __DIR__ . '/src/Slot.php';
require_once __DIR__ . '/src/Day.php';
require_once __DIR__ . '/src/TutorSeason.php';
require_once __DIR__ . '/src/Report.php';
require_once __DIR__ . '/src/SnapshotStore.php';
require_once __DIR__ . '/src/SeasonAvailability.php';
require_once __DIR__ . '/src/DebugTutorCommand.php';
require_once __DIR__ . '/src/ReportRenderer.php';
require_once __DIR__ . '/src/ReportCommand.php';
require_once __DIR__ . '/src/RenderOnlyCommand.php';
require_once __DIR__ . '/src/ErrorReporter.php';

const USAGE = <<<'TXT'
Usage: php acuity-report.php [options]

Builds a self-contained HTML report of the sessions set up in each Acuity
calendar for the season set in config.php.

Options:
  --out=FILE          Write the report to FILE
                      (default: out/availability-YYYY-MM-DD_HHMMSS.html)
  --render-only       Write the report from the saved data only: no API calls,
                      no .env needed, saved data unchanged
  --list              List calendars and appointment types with their IDs
  --debug-tutor=ID    Print the slots per date for one calendar, each free or
                      booked, with its source (live, or stored and when);
                      writes nothing
  --help              Show this help

Settings are read from config.php (copy config.example.php) and credentials
from .env (ACUITY_USER_ID, ACUITY_API_KEY).

Each report run saves every day's sessions to data/availability-cache.json,
cumulatively: what the API shows now replaces the saved sessions, and what
it no longer shows (past days, the minimum-notice period) is kept. Calendars
no longer offering a listed type are removed. include_calendars and
exclude_calendars in config.php choose the calendars to report.
To save every season day, make the first run at least min_notice_hours + 1
hours before the season's first day begins, while the season still ends at
least a day inside max_days_ahead.

TXT;

/**
 * @param list<string> $args
 * @return array<string, string|true>
 */
function parseArgs(array $args): array
{
    $flags = ['help', 'list', 'render-only'];
    $valued = ['out', 'debug-tutor'];

    // Errors never echo user input except a recognised option name: a
    // credential pasted on the command line by mistake must not be printed.
    $options = [];
    foreach ($args as $position => $arg) {
        $unexpected = 'Unexpected argument #' . ($position + 1) . ' (not shown)';
        if (preg_match('/\A--([a-z-]+)(?:=(.*))?\z/s', $arg, $m) !== 1) {
            throw new RuntimeException($unexpected);
        }
        $name = $m[1];
        $value = $m[2] ?? null;

        if (in_array($name, $flags, true)) {
            if ($value !== null) {
                throw new RuntimeException("Option --{$name} takes no value");
            }
            $options[$name] = true;
        } elseif (in_array($name, $valued, true)) {
            if ($value === null || $value === '') {
                throw new RuntimeException("Option --{$name} needs a value: --{$name}=...");
            }
            if ($name === 'debug-tutor' && preg_match('/\A[1-9]\d*\z/', $value) !== 1) {
                throw new RuntimeException('Option --debug-tutor needs a numeric calendar ID (see --list)');
            }
            $options[$name] = $value;
        } else {
            throw new RuntimeException($unexpected);
        }
    }

    return $options;
}

function main(array $argv): int
{
    try {
        $options = parseArgs(array_slice($argv, 1));
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n\n" . USAGE);
        return 2;
    }

    if (isset($options['help'])) {
        echo USAGE;
        return 0;
    }

    try {
        $config = Config::load(__DIR__ . '/config.php');
        if (isset($options['render-only'])) {
            // Saved data only: no credentials are read.
            echo RenderOnlyCommand::run($config, __DIR__ . '/data', __DIR__ . '/out', $options['out'] ?? null, new \DateTimeImmutable());
            return 0;
        }
        $client = new AcuityClient(Credentials::fromEnv(Env::load(__DIR__ . '/.env')));

        if (isset($options['list'])) {
            echo ListCommand::run($client);
            return 0;
        }
        $progress = static function (string $message): void {
            fwrite(STDERR, $message . "\n");
        };
        if (isset($options['debug-tutor'])) {
            echo DebugTutorCommand::run($client, $config, __DIR__ . '/data', (int) $options['debug-tutor'], new \DateTimeImmutable(), $progress);
            return 0;
        }

        echo ReportCommand::run(
            $client,
            $config,
            __DIR__ . '/data',
            __DIR__ . '/out',
            $options['out'] ?? null,
            new \DateTimeImmutable(),
            null,
            $progress,
        );
        return 0;
    } catch (\Throwable $e) {
        fwrite(STDERR, ErrorReporter::describe($e) . "\n");
        return 1;
    }
}

exit(main($argv));
