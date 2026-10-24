<?php

namespace Tamara\Tests;

use Tamara\Exceptions\TamaraSecurityException;
use Tamara\Security\TamaraSignatureVerifier;

class TamaraSignatureVerifierTest
{
    public function run(): void
    {
        $this->testSuccessfulVerification();
        $this->testInvalidSignatureFailsClosed();
        $this->testExpiredTokenFailsClosed();
        $this->testMalformedTokenFailsClosed();
        $this->testInsecureAlgorithmRejected();
        $this->testTokenExtraction();
    }

    protected function testSuccessfulVerification(): void
    {
        $secret = 'test-notification-secret-key-12345';
        $verifier = new TamaraSignatureVerifier($secret);

        $payload = [
            'iss' => 'Tamara',
            'order_id' => 'ord-123',
            'exp' => time() + 300,
            'iat' => time() - 10,
        ];

        $token = TamaraSignatureVerifier::generateToken($payload, $secret);
        $decoded = $verifier->verify($token);

        assert($decoded['order_id'] === 'ord-123', 'Decoded claims must match');
        assert($decoded['iss'] === 'Tamara', 'Issuer must match');
    }

    protected function testInvalidSignatureFailsClosed(): void
    {
        $secret = 'test-secret';
        $wrongSecret = 'wrong-secret';
        $verifier = new TamaraSignatureVerifier($secret);

        $token = TamaraSignatureVerifier::generateToken(['order_id' => '1'], $wrongSecret);

        $caught = false;
        try {
            $verifier->verify($token);
        } catch (TamaraSecurityException $e) {
            $caught = true;
            assert(str_contains($e->getMessage(), 'signature mismatch'), 'Must report signature mismatch');
        }
        assert($caught, 'Must throw exception on invalid signature');
    }

    protected function testExpiredTokenFailsClosed(): void
    {
        $secret = 'test-secret';
        $verifier = new TamaraSignatureVerifier($secret, leeway: 0);

        $token = TamaraSignatureVerifier::generateToken([
            'order_id' => '1',
            'exp' => time() - 10,
        ], $secret);

        $caught = false;
        try {
            $verifier->verify($token);
        } catch (TamaraSecurityException $e) {
            $caught = true;
            assert(str_contains($e->getMessage(), 'expired'), 'Must report expired token');
        }
        assert($caught, 'Must throw exception on expired token');
    }

    protected function testMalformedTokenFailsClosed(): void
    {
        $verifier = new TamaraSignatureVerifier('secret');

        $caught = false;
        try {
            $verifier->verify('not.a.valid.jwt.token');
        } catch (TamaraSecurityException $e) {
            $caught = true;
        }
        assert($caught, 'Must throw exception on malformed token');
    }

    protected function testInsecureAlgorithmRejected(): void
    {
        $secret = 'secret';
        $verifier = new TamaraSignatureVerifier($secret);

        // Header with alg: none
        $head = TamaraSignatureVerifier::base64UrlEncode((string) json_encode(['alg' => 'none', 'typ' => 'JWT']));
        $body = TamaraSignatureVerifier::base64UrlEncode((string) json_encode(['order_id' => '1']));
        $token = "{$head}.{$body}.";

        $caught = false;
        try {
            $verifier->verify($token);
        } catch (TamaraSecurityException $e) {
            $caught = true;
            assert(str_contains($e->getMessage(), 'HS256'), 'Must reject non-HS256 algorithms');
        }
        assert($caught, 'Must reject none algorithm');
    }

    protected function testTokenExtraction(): void
    {
        $raw = 'my-jwt-token';
        assert(TamaraSignatureVerifier::extractToken($raw) === 'my-jwt-token');
        assert(TamaraSignatureVerifier::extractToken(authHeader: 'Bearer my-jwt-token') === 'my-jwt-token');
        assert(TamaraSignatureVerifier::extractToken(query: ['tamaraToken' => 'my-jwt-token']) === 'my-jwt-token');
        assert(TamaraSignatureVerifier::extractToken(query: 'tamaraToken=my-jwt-token&foo=bar') === 'my-jwt-token');
    }
}

