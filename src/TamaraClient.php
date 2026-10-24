<?php

namespace Tamara;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tamara\Exceptions\TamaraApiException;
use Tamara\Exceptions\TamaraException;
use Tamara\Exceptions\TamaraSecurityException;
use Tamara\Exceptions\TamaraValidationException;
use Tamara\Security\TamaraOrderStateMachine;
use Tamara\Security\TamaraSanitizer;
use Tamara\Security\TamaraSignatureVerifier;

/**
 * Pure PHP HTTP Client for the Tamara API.
 * Uses cURL and PSR-3 Logger (defaults to NullLogger). Zero framework dependencies.
 */
class TamaraClient
{
    const API_URL_PRODUCTION = 'https://api.tamara.co';
    const API_URL_SANDBOX    = 'https://api-sandbox.tamara.co';

    /** @var string */
    protected $merchantToken;

    /** @var string */
    protected $notificationToken;

    /** @var string */
    protected $apiUrl;

    /** @var LoggerInterface */
    protected $logger;

    /** @var int Timeout in seconds */
    protected $timeout;

    public function __construct(
        string $merchantToken,
        string $notificationToken = '',
        bool $sandbox = false,
        ?string $apiUrl = null,
        ?LoggerInterface $logger = null,
        int $timeout = 30
    ) {
        if (empty(trim($merchantToken))) {
            throw new TamaraException('Tamara merchant token cannot be empty.');
        }

        $this->merchantToken     = trim($merchantToken);
        $this->notificationToken = trim($notificationToken);
        $this->apiUrl            = $apiUrl ?? ($sandbox ? self::API_URL_SANDBOX : self::API_URL_PRODUCTION);
        $this->logger            = $logger ?? new NullLogger();
        $this->timeout           = $timeout;
    }

    public function getApiUrl(): string
    {
        return $this->apiUrl;
    }

    public function getNotificationToken(): string
    {
        return $this->notificationToken;
    }

    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Create Checkout Session
     *
     * @param array $orderData
     * @return array
     * @throws TamaraApiException
     */
    public function createCheckout(array $orderData): array
    {
        $response = $this->request('/checkout', 'POST', $orderData);

        if ($response['status'] === 200 && isset($response['body']['checkout_url'])) {
            return $response['body'];
        }

        $msg = $response['body']['message'] ?? 'Unable to create Tamara checkout session';
        throw new TamaraApiException($msg, $response['status'], $response['body']);
    }

    /**
     * Get Order Details
     *
     * @param string $orderId
     * @return array
     * @throws TamaraApiException
     */
    public function getOrder(string $orderId): array
    {
        $response = $this->request('/orders/' . rawurlencode($orderId), 'GET');

        if ($response['status'] === 200) {
            return $response['body'];
        }

        $msg = $response['body']['message'] ?? "Unable to retrieve order {$orderId}";
        throw new TamaraApiException($msg, $response['status'], $response['body']);
    }

    /**
     * Authorise an Order
     *
     * @param string $orderId
     * @return array
     * @throws TamaraApiException
     */
    public function authoriseOrder(string $orderId): array
    {
        $response = $this->request('/orders/' . rawurlencode($orderId) . '/authorise', 'POST');

        if ($response['status'] === 200) {
            return $response['body'];
        }

        $msg = $response['body']['message'] ?? "Unable to authorise order {$orderId}";
        throw new TamaraApiException($msg, $response['status'], $response['body']);
    }

    /**
     * Capture payment
     *
     * @param string $orderId
     * @param float $amount
     * @param string $currency
     * @param array $items
     * @param array $shippingInfo Required fulfillment metadata
     * @param float $shippingAmount
     * @param float $taxAmount
     * @param float $discountAmount
     * @return array
     * @throws TamaraApiException
     */
    public function capturePayment(
        string $orderId,
        float $amount,
        string $currency = 'SAR',
        array $items = [],
        array $shippingInfo = [],
        float $shippingAmount = 0.0,
        float $taxAmount = 0.0,
        float $discountAmount = 0.0
    ): array
    {
        foreach (['shipped_at', 'shipping_company', 'tracking_number', 'tracking_url'] as $field) {
            if (!isset($shippingInfo[$field]) || !is_string($shippingInfo[$field]) || trim($shippingInfo[$field]) === '') {
                throw new TamaraValidationException("Tamara capture shipping_info requires a non-empty {$field}.");
            }
        }
        $data = [
            'order_id' => $orderId,
            'total_amount' => [
                'amount'   => $amount,
                'currency' => $currency,
            ],
            'shipping_info' => $shippingInfo,
            'items' => $items,
            'shipping_amount' => ['amount' => $shippingAmount, 'currency' => $currency],
            'tax_amount' => ['amount' => $taxAmount, 'currency' => $currency],
            'discount_amount' => ['amount' => $discountAmount, 'currency' => $currency],
        ];

        $response = $this->request('/payments/capture', 'POST', $data);

        if ($response['status'] === 200) {
            return $response['body'];
        }

        $msg = $response['body']['message'] ?? "Unable to capture payment for order {$orderId}";
        throw new TamaraApiException($msg, $response['status'], $response['body']);
    }

    /**
     * Cancel an Order
     *
     * @param string $orderId
     * @param array $data
     * @return array
     * @throws TamaraApiException
     */
    public function cancelOrder(string $orderId, array $data = []): array
    {
        $order = $this->getOrder($orderId);
        if (TamaraOrderStateMachine::normalizeStatus((string) ($order['status'] ?? '')) !== TamaraOrderStateMachine::STATUS_AUTHORISED) {
            throw new TamaraValidationException('Tamara cancellation is only allowed for authorised orders.');
        }
        $response = $this->request('/orders/' . rawurlencode($orderId) . '/cancel', 'POST', $data);

        if ($response['status'] === 200) {
            return $response['body'];
        }

        $msg = $response['body']['message'] ?? "Unable to cancel order {$orderId}";
        throw new TamaraApiException($msg, $response['status'], $response['body']);
    }

    /**
     * Verify Tamara notification token (JWT) using Tamara's documented HS256 scheme.
     *
     * @param string $token (JWT string or Bearer token header)
     * @return array Decoded payload claims
     * @throws TamaraSecurityException|TamaraException
     */
    public function verifyWebhookSignature(string $token): array
    {
        if (empty($this->notificationToken)) {
            throw new TamaraException('Tamara notification token is not configured.');
        }

        $extracted = TamaraSignatureVerifier::extractToken($token);
        $verifier  = new TamaraSignatureVerifier($this->notificationToken);

        return $verifier->verify($extracted ?? $token);
    }

    /**
     * Low-level cURL HTTP request with sanitized logs and security headers
     *
     * @param string $endpoint
     * @param string $method
     * @param array $data
     * @return array{status: int, body: array}
     * @throws TamaraApiException
     */
    protected function request(string $endpoint, string $method = 'GET', array $data = []): array
    {
        $url = rtrim($this->apiUrl, '/') . '/' . ltrim($endpoint, '/');

        $headers = [
            'Authorization: Bearer ' . $this->merchantToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) json_encode($data));
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, (string) json_encode($data));
            }
        }

        $rawResponse = curl_exec($ch);
        $httpCode    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError   = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->logger->error("Tamara API connection failure [{$method} {$endpoint}]: {$curlError}");
            throw new TamaraApiException("Tamara connection error: {$curlError}", 0);
        }

        $body = json_decode((string) $rawResponse, true) ?? [];

        // Redact any PII, credentials, or sensitive data before logging
        $sanitizedResponse = TamaraSanitizer::sanitize($body);

        $this->logger->info("Tamara API Request completed [{$method} {$endpoint}] with status {$httpCode}", [
            'method'   => $method,
            'endpoint' => $endpoint,
            'status'   => $httpCode,
            'response' => $sanitizedResponse,
        ]);

        return ['status' => $httpCode, 'body' => $body];
    }
}
