<?php

namespace Tamara\Tests;

use Tamara\Security\TamaraSanitizer;

class TamaraSanitizerTest
{
    public function run(): void
    {
        $this->testRedactionOfSensitiveFields();
        $this->testJsonSanitization();
    }

    protected function testRedactionOfSensitiveFields(): void
    {
        $sensitiveData = [
            'merchant_token' => 'top-secret-token',
            'order_id' => '12345',
            'consumer' => [
                'first_name' => 'John',
                'email' => 'john@example.com',
                'phone_number' => '+966500000000',
            ],
            'card_number' => '4111111111111111',
            'total_amount' => 150.00,
        ];

        $sanitized = TamaraSanitizer::sanitize($sensitiveData);

        assert($sanitized['merchant_token'] === '[REDACTED]', 'Token must be redacted');
        assert($sanitized['consumer']['first_name'] === '[REDACTED]', 'PII must be redacted');
        assert($sanitized['consumer']['email'] === '[REDACTED]', 'Email must be redacted');
        assert($sanitized['consumer']['phone_number'] === '[REDACTED]', 'Phone must be redacted');
        assert($sanitized['card_number'] === '[REDACTED]', 'Card number must be redacted');
        assert($sanitized['order_id'] === '12345', 'Safe fields must be preserved');
        assert($sanitized['total_amount'] === 150.00, 'Amount must be preserved');
    }

    protected function testJsonSanitization(): void
    {
        $json = json_encode(['email' => 'user@test.com', 'order_id' => 'abc']);
        $cleaned = TamaraSanitizer::sanitizeJson($json);

        assert(str_contains($cleaned, '[REDACTED]'), 'PII in JSON must be redacted');
        assert(str_contains($cleaned, 'abc'), 'Safe values in JSON must remain');
    }
}

