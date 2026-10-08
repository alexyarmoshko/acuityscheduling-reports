<?php

declare(strict_types=1);

namespace AcuityReport;

use RuntimeException;

/**
 * Minimal .env reader for credentials.
 *
 * Rules: blank lines and lines starting with "#" are skipped; each other line is
 * split on the first "="; key and value are trimmed; one matching pair of
 * surrounding quotes is stripped from the value. Nothing else is interpreted, so
 * "#", ";" and "=" inside a value are kept as-is.
 *
 * Error messages name line numbers only, never line contents, so a malformed
 * file cannot leak a secret to the console.
 */
final class Env
{
    /** @return array<string, string> */
    public static function load(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("Credentials file not found or not readable: {$path}");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Could not read credentials file: {$path}");
        }

        try {
            return self::parse($contents);
        } catch (RuntimeException $e) {
            // Rethrow without chaining: the original trace records parse()'s
            // argument, which is the whole file. This one starts here, where the
            // only argument is the path.
            throw new RuntimeException($e->getMessage());
        }
    }

    /** @return array<string, string> */
    public static function parse(#[\SensitiveParameter] string $contents): array
    {
        // Windows tools often write UTF-16 (PowerShell ">", Notepad "Unicode").
        if (str_contains($contents, "\0")) {
            throw new RuntimeException('Credentials file is not UTF-8 (looks like UTF-16): save it as UTF-8');
        }

        // Editors on Windows may save UTF-8 with a byte order mark.
        if (str_starts_with($contents, "\u{FEFF}")) {
            $contents = substr($contents, 3);
        }

        $values = [];
        foreach (preg_split('/\R/', $contents) as $index => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                throw new RuntimeException('Malformed .env line ' . ($index + 1) . ': expected KEY=value');
            }

            $key = trim(substr($line, 0, $pos));
            if ($key === '') {
                throw new RuntimeException('Malformed .env line ' . ($index + 1) . ': empty key');
            }

            $values[$key] = self::unquote(trim(substr($line, $pos + 1)));
        }

        return $values;
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            if (($first === '"' || $first === "'") && $value[strlen($value) - 1] === $first) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }
}
