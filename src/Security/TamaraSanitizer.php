<?php

namespace TamaraPhpSdk\Security;

/**
 * Sanitizes sensitive data from payloads, logs, and stored audit records.
 * Prevents leaks of PII, secrets, authentication tokens, and financial data.
 */
class TamaraSanitizer
{
    /** @var string[] Keys whose values will be redacted */
    const SENSITIVE_KEYS = [
        // Tokens and secrets
        'merchant_token',
        'notification_token',
        'api_token',
        'token',
        'tamaratoken',
        'access_token',
        'secret',
        'password',
        'authorization',
        'bearer',
        'api_key',

        // Personal Identifiable Information (PII)
        'first_name',
        'last_name',
        'full_name',
        'customer_name',
        'phone',
        'phone_number',
        'customer_phone',
        'email',
        'customer_email',
        'national_id',
        'id_number',

        // Addresses
        'address',
        'billing_address',
        'shipping_address',
        'line1',
        'line2',
        'street',

        // Card & Financial data
        'card_number',
        'cvv',
        'cvc',
        'expiry',
        'expiration_date',

        // Raw audit strings
        'raw_payload',
        'authorisation_data',
    ];

    /**
     * Recursively sanitize array data, masking any sensitive keys.
     *
     * @param mixed $data
     * @param string[] $additionalKeys
     * @param string $mask
     * @return mixed
     */
    public static function sanitize(mixed $data, array $additionalKeys = [], string $mask = '[REDACTED]'): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        $sensitive = array_merge(self::SENSITIVE_KEYS, array_map('strtolower', $additionalKeys));
        $sanitized = [];

        foreach ($data as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if (in_array($normalizedKey, $sensitive, true)) {
                $sanitized[$key] = $mask;
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = self::sanitize($value, $additionalKeys, $mask);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize a JSON string by decoding, redacting sensitive keys, and re-encoding.
     */
    public static function sanitizeJson(string $json, array $additionalKeys = []): string
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return '[INVALID_OR_NON_JSON_PAYLOAD]';
        }

        $sanitized = self::sanitize($decoded, $additionalKeys);
        return (string) json_encode($sanitized);
    }
}

