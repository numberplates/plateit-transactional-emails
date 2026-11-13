<?php
declare(strict_types=1);

namespace App;

/** Forwards the original signed webhook to a configured HTTP endpoint. */
final class WebhookRelay
{
    /** Returns true only when the endpoint responds with a 2XX status. */
    public function __invoke(string $url, array $headers, string $raw): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) ||
            !in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true)) {
            return false;
        }

        $requestHeaders = ['Content-Type: application/json'];
        foreach (['signature', 'x-webhook-event', 'x-webhook-contact-hint'] as $name) {
            if (isset($headers[$name])) {
                if (!is_string($headers[$name]) || strpbrk($headers[$name], "\r\n") !== false) {
                    return false;
                }
                $requestHeaders[] = $name . ': ' . $headers[$name];
            }
        }

        $curl = curl_init($url);
        try {
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $raw,
                CURLOPT_HTTPHEADER => $requestHeaders,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                // Discard the response body without buffering it in memory.
                CURLOPT_WRITEFUNCTION => static fn ($handle, string $data): int => strlen($data),
            ]);
            $result = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            return $result !== false && $status >= 200 && $status < 300;
        } finally {
            unset($curl);
        }
    }
}
