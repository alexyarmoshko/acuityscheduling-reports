<?php

declare(strict_types=1);

namespace AcuityReport;

use RuntimeException;

final class CurlTransport implements HttpTransport
{
    public function get(string $url, Credentials $credentials): array
    {
        $headers = [];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $credentials->basicAuth(),
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'acuity-availability-report',
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            // curl_error() describes the network failure (DNS, TLS, timeout);
            // it does not include the credentials.
            $message = curl_error($ch);
            $code = curl_errno($ch);
            curl_close($ch);
            throw new RuntimeException("Network error (curl {$code}): {$message}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'headers' => $headers, 'body' => (string) $body];
    }
}
