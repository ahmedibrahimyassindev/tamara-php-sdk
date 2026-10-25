<?php

namespace Tamara\Security;

use Tamara\Exceptions\TamaraSecurityException;

/**
 * Validates Tamara webhook notifications using Tamara's documented HS256 JWT scheme.
 *
 * Tamara authenticates webhooks via a JWT (`tamaraToken`) delivered either:
 *  1. In the HTTP Authorization header: `Authorization: Bearer <tamaraToken>`
 *  2. As a query parameter: `?tamaraToken=<tamaraToken>`
 *
 * The JWT is signed with HMAC-SHA256 (HS256) using the merchant's Tamara Notification Token.
 */
class TamaraSignatureVerifier
{
    /** @var string */
    protected $notificationToken;

    /** @var int Allowed clock skew in seconds */
    protected $leeway;

    public function __construct(string $notificationToken, int $leeway = 60)
    {
        if (empty(trim($notificationToken))) {
            throw new TamaraSecurityException('Tamara notification token cannot be empty.');
        }

        $this->notificationToken = trim($notificationToken);
        $this->leeway            = max(0, $leeway);
    }

    /**
     * Verify a Tamara JWT token against the configured notification token.
     * Returns the decoded payload claims on success, or throws TamaraSecurityException on failure.
     *
     * @param string $jwt
     * @return array Decoded payload claims
     * @throws TamaraSecurityException
     */
    public function verify(string $jwt): array
    {
        $jwt = trim($jwt);
        if (empty($jwt)) {
            throw new TamaraSecurityException('Tamara notification token (JWT) is empty.');
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new TamaraSecurityException('Invalid JWT structure: token must contain exactly 3 segments.');
        }

        [$headB64, $payloadB64, $sigB64] = $parts;

        // 1. Verify header
        $headerJson = self::base64UrlDecode($headB64);
        $header     = json_decode($headerJson, true);
        if (!is_array($header)) {
            throw new TamaraSecurityException('Invalid JWT header: unable to decode JSON.');
        }

        $alg = $header['alg'] ?? null;
        if (!is_string($alg) || $alg !== 'HS256') {
            throw new TamaraSecurityException('Unsupported or insecure JWT algorithm. Tamara requires HS256.');
        }

        // 2. Verify signature
        $expectedSig = hash_hmac('sha256', "{$headB64}.{$payloadB64}", $this->notificationToken, true);
        $actualSig   = self::base64UrlDecode($sigB64);

        if (!hash_equals($expectedSig, $actualSig)) {
            throw new TamaraSecurityException('Tamara webhook signature verification failed: signature mismatch.');
        }

        // 3. Verify payload claims
        $payloadJson = self::base64UrlDecode($payloadB64);
        $payload     = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            throw new TamaraSecurityException('Invalid JWT payload: unable to decode JSON claims.');
        }

        $now = time();

        // Exp claim (Expiration Time)
        if (isset($payload['exp']) && is_numeric($payload['exp'])) {
            if (($now - $this->leeway) > (int) $payload['exp']) {
                throw new TamaraSecurityException('Tamara webhook notification token has expired.');
            }
        }

        // Nbf claim (Not Before)
        if (isset($payload['nbf']) && is_numeric($payload['nbf'])) {
            if (($now + $this->leeway) < (int) $payload['nbf']) {
                throw new TamaraSecurityException('Tamara webhook notification token is not yet valid (nbf).');
            }
        }

        // Iat claim (Issued At - reject if issued in unreasonable future)
        if (isset($payload['iat']) && is_numeric($payload['iat'])) {
            if (($now + $this->leeway) < (int) $payload['iat']) {
                throw new TamaraSecurityException('Tamara webhook notification token was issued in the future.');
            }
        }

        return $payload;
    }

    /**
     * Extract token from authorization header string, query array/string, or explicit value.
     *
     * @param string|null $token Explicit token
     * @param string|null $authHeader HTTP Authorization header value (e.g. 'Bearer ...')
     * @param array|string|null $query Query string or array (e.g. $_GET)
     * @return string|null
     */
    public static function extractToken(
        ?string $token = null,
        ?string $authHeader = null,
        mixed $query = null
    ): ?string {
        if (!empty($token)) {
            return trim(preg_replace('/^Bearer\s+/i', '', trim($token)));
        }

        if (!empty($authHeader)) {
            if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
                return trim($matches[1]);
            }
            return trim($authHeader);
        }

        if (is_array($query) && !empty($query['tamaraToken'])) {
            return trim((string) $query['tamaraToken']);
        }

        if (is_string($query) && !empty($query)) {
            parse_str(ltrim($query, '?'), $parsed);
            if (!empty($parsed['tamaraToken'])) {
                return trim((string) $parsed['tamaraToken']);
            }
        }

        return null;
    }

    /**
     * Generate a valid Tamara HS256 JWT (useful for testing or mocking).
     */
    public static function generateToken(array $payload, string $secret): string
    {
        $header  = ['alg' => 'HS256', 'typ' => 'JWT'];
        $headB64 = self::base64UrlEncode((string) json_encode($header));
        $bodyB64 = self::base64UrlEncode((string) json_encode($payload));
        $sig     = hash_hmac('sha256', "{$headB64}.{$bodyB64}", $secret, true);
        $sigB64  = self::base64UrlEncode($sig);

        return "{$headB64}.{$bodyB64}.{$sigB64}";
    }

    public static function base64UrlDecode(string $input): string
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $input .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($input, '-_', '+/'), true);
        return $decoded !== false ? $decoded : '';
    }

    public static function base64UrlEncode(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }
}

