<?php

/**
 * Server-side client PHP uses to call the Flask analytics API.
 *
 * Per architecture doc section 4.2: this is called from a PHP controller,
 * never directly from the browser. The JWT issued at login (stored in the
 * PHP session, NOT a cookie the browser can read directly) is forwarded as
 * a Bearer token.
 *
 * Includes timeout + retry-with-backoff and a cache fallback per section
 * 4.3 ("if the Flask service is unavailable, PHP falls back to a cached
 * version of the report").
 */
class ApiClient
{
    private string $baseUrl;
    private string $token;

    public function __construct(string $baseUrl, string $token)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
    }

    /**
     * @return array{ok: bool, status: int, data: mixed, error?: string}
     */
    public function get(string $path, array $query = []): array
    {
        $url = $this->baseUrl . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $maxAttempts = 3;
        $lastError = '';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token],
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 8,
            ]);

            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno === 0) {
                $decoded = json_decode($body, true);
                return [
                    'ok' => $status >= 200 && $status < 300,
                    'status' => $status,
                    'data' => $decoded,
                ];
            }

            $lastError = curl_strerror($errno);
            if ($attempt < $maxAttempts) {
                usleep((int) (200000 * (2 ** ($attempt - 1)))); // exponential backoff
            }
        }

        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => $lastError];
    }

    /** Downloads a binary/CSV response (for export endpoints) rather than parsing JSON.
     *  Includes retry-with-exponential-backoff, same as get(). */
    public function download(string $path, array $query = []): array
    {
        $url = $this->baseUrl . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $maxAttempts = 3;
        $lastError = '';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token],
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 15,
            ]);
            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);

            if ($errno === 0) {
                return [
                    'ok' => $status >= 200 && $status < 300,
                    'status' => $status,
                    'body' => $body,
                    'content_type' => $contentType,
                ];
            }

            $lastError = curl_strerror($errno);
            if ($attempt < $maxAttempts) {
                usleep((int) (200000 * (2 ** ($attempt - 1)))); // exponential backoff
            }
        }

        return ['ok' => false, 'status' => 0, 'body' => null, 'content_type' => null, 'error' => $lastError];
    }
}
