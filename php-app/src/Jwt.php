<?php
/**
 * Minimal HS256 JWT encode/decode, no Composer dependency.
 *
 * Per architecture doc section 5.1: PHP issues the JWT on login, Flask
 * verifies it on every API call. Both sides must agree on the same
 * algorithm (HS256) and the same shared secret (JWT_SECRET env var).
 *
 * This is intentionally minimal — for a production deployment, swap this
 * for firebase/php-jwt via Composer once you have Packagist access from
 * your build environment. The wire format (base64url header.payload.sig)
 * is identical either way, so nothing on the Flask side needs to change.
 */
class Jwt
{
    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * @param array $payload Claims to encode. 'exp' (unix timestamp) is
     *                        added automatically if not present.
     */
    public static function encode(array $payload, string $secret, int $ttlSeconds = 3600): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];

        if (!isset($payload['iat'])) {
            $payload['iat'] = time();
        }
        if (!isset($payload['exp'])) {
            $payload['exp'] = time() + $ttlSeconds;
        }

        $segments = [
            self::base64UrlEncode(json_encode($header)),
            self::base64UrlEncode(json_encode($payload)),
        ];

        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $secret, true);
        $segments[] = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    /**
     * Verifies signature and expiry. Returns the decoded payload array on
     * success, or null on any failure (bad signature, expired, malformed).
     */
    public static function decode(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$headerB64, $payloadB64, $sigB64] = $parts;

        // Verify header algorithm matches HS256 — prevents algorithm substitution attacks.
        $header = json_decode(self::base64UrlDecode($headerB64), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
            return null;
        }

        $signingInput = $headerB64 . '.' . $payloadB64;
        $expectedSig = hash_hmac('sha256', $signingInput, $secret, true);
        $actualSig = self::base64UrlDecode($sigB64);

        if (!hash_equals($expectedSig, $actualSig)) {
            return null;
        }

        $payload = json_decode(self::base64UrlDecode($payloadB64), true);
        if (!is_array($payload)) {
            return null;
        }

        if (isset($payload['exp']) && time() >= $payload['exp']) {
            return null; // expired
        }

        return $payload;
    }
}
