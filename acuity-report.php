<?php

declare(strict_types=1);

namespace AcuityReport;

use RuntimeException;

// Stack traces must never show argument values: they could include credentials.
ini_set('zend.exception_ignore_args', '1');

require_once __DIR__ . '/src/Env.php';
require_once __DIR__ . '/src/Config.php';

const USAGE = <<<'TXT'
Usage: php acuity-report.php [options]

Builds a self-contained HTML report of each Acuity calendar's free and booked
slots for the season set in config.php.

Options:
  --out=FILE          Write the report to FILE
                      (default: out/availability-<date>.html)
  --list              List calendars and appointment types with their IDs
  --debug-tutor=ID    Print free and booked starts per date for one calendar
  --spike             Save sample API responses to spike/ (development)
  --help              Show this help

Settings are read from config.php (copy config.example.php) and credentials
from .env (ACUITY_USER_ID, ACUITY_API_KEY).

TXT;

/**
 * @param list<string> $args
 * @return array<string, string|true>
 */
function parseArgs(array $args): array
{
    $flags = ['help', 'list', 'spike'];
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
        Config::load(__DIR__ . '/config.php');
        $env = Env::load(__DIR__ . '/.env');
        foreach (['ACUITY_USER_ID', 'ACUITY_API_KEY'] as $key) {
            if (($env[$key] ?? '') === '') {
                throw new RuntimeException("{$key} is missing from .env");
            }
        }
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
        return 1;
    }

    fwrite(STDERR, "Settings and credentials loaded. Fetching data is not implemented yet.\n");
    return 1;
}

exit(main($argv));
