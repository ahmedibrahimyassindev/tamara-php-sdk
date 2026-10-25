<?php

namespace Tamara;

use Psr\Log\LoggerInterface;
use Tamara\Contracts\TamaraOrderableInterface;
use Tamara\Exceptions\TamaraApiException;
use Tamara\Security\TamaraOrderStateMachine;
use Throwable;

/**
 * High-level Tamara Service Orchestrator
 */
class TamaraService
{
    /** @var TamaraClient */
    protected $client;

    /** @var TamaraOrderBuilder */
    protected $builder;

    /** @var float Gateway markup/fee ratio (disabled by default) */
    protected static $feeRatio = 1.0;

    public function __construct(TamaraClient $client, ?TamaraOrderBuilder $builder = null)
    {
        $this->client  = $client;
        $this->builder = $builder ?? new TamaraOrderBuilder();
    }

    /**
     * Set a custom markup or commission ratio.
     *
     * @param float $ratio (e.g., 1.076345 for +7.6345% or 1.0 for 0% fee markup)
     */
    public static function setFeeRatio(float $ratio): void
    {
        self::$feeRatio = $ratio;
    }

    public static function getFeeRatio(): float
    {
        return self::$feeRatio;
    }

    /**
     * Create client instance directly from environment variables
     */
    public static function fromEnv(?LoggerInterface $logger = null): self
    {
        $merchantToken     = (string) (getenv('TAMARA_MERCHANT_TOKEN') ?: getenv('TAMARA_API_TOKEN'));
        $notificationToken = (string) (getenv('TAMARA_NOTIFICATION_TOKEN') ?: '');
        $sandbox           = filter_var(getenv('TAMARA_SANDBOX') ?: false, FILTER_VALIDATE_BOOLEAN);

        $client = new TamaraClient($merchantToken, $notificationToken, $sandbox, null, $logger);
        return new self($client);
    }

    /**
     * Create a pre-configured, secure TamaraWebhookProcessor using this service's client credentials.
     */
    public function createWebhookProcessor(?LoggerInterface $logger = null): TamaraWebhookProcessor
    {
        $processor = new TamaraWebhookProcessor(null, null, $logger);
        $processor->setAuthoriser(fn (string $orderId) => $this->authoriseOrder($orderId));
        $processor->setOrderStatusResolver(fn (string $orderId) => $this->getOrder($orderId)['status'] ?? null);

        $token = $this->client->getNotificationToken();
        if (!empty($token)) {
            $processor->setNotificationToken($token);
        }

        return $processor;
    }

    /**
     * Initiate checkout session for any orderable entity
     *
     * @param TamaraOrderableInterface $entity
     * @param string $successUrl
     * @param string $failureUrl
     * @param string $cancelUrl
     * @param string|null $notificationUrl
     * @param string $locale
     * @param string $description
     * @return string Checkout redirect URL
     * @throws Exceptions\TamaraApiException
     * @throws Exceptions\TamaraValidationException
     */
    public function initiateCheckout(
        TamaraOrderableInterface $entity,
        string $successUrl,
        string $failureUrl,
        string $cancelUrl,
        ?string $notificationUrl = null,
        string $locale = 'ar_SA',
        string $description = 'Online Order'
    ): string {
        if (method_exists($entity, 'assertTamaraCheckoutPersistenceReady')) {
            $entity->assertTamaraCheckoutPersistenceReady();
        }

        $payload = $this->builder->build(
            $entity,
            $successUrl,
            $failureUrl,
            $cancelUrl,
            $notificationUrl,
            $locale,
            $description
        );

        $result = $this->client->createCheckout($payload);

        $orderId = (string) ($result['order_id'] ?? '');
        $checkoutId = isset($result['checkout_id']) ? (string) $result['checkout_id'] : null;
        try {
            $entity->onTamaraCheckoutCreated(
                $orderId,
                $checkoutId,
                (string) ($payload['order_reference_id'] ?? '')
            );
        } catch (Throwable $e) {
            $this->client->getLogger()->error('Tamara checkout was created but local persistence failed.', [
                'order_id' => $orderId,
                'checkout_id' => $checkoutId,
                'order_reference_id' => $payload['order_reference_id'] ?? null,
                'exception' => get_class($e),
            ]);
            throw $e;
        }

        return $result['checkout_url'];
    }

    public function getClient(): TamaraClient
    {
        return $this->client;
    }

    public function authoriseOrder(string $orderId): array
    {
        try {
            return $this->client->authoriseOrder($orderId);
        } catch (TamaraApiException $e) {
            try {
                $order = $this->client->getOrder($orderId);
            } catch (Throwable $lookupError) {
                throw $e;
            }

            $status = TamaraOrderStateMachine::normalizeStatus((string) ($order['status'] ?? ''));
            if (in_array($status, [
                TamaraOrderStateMachine::STATUS_AUTHORISED,
                TamaraOrderStateMachine::STATUS_PARTIALLY_CAPTURED,
                TamaraOrderStateMachine::STATUS_FULLY_CAPTURED,
            ], true)) {
                return $order;
            }
            throw $e;
        }
    }

    public function getOrder(string $orderId): array
    {
        return $this->client->getOrder($orderId);
    }

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
        return $this->client->capturePayment($orderId, $amount, $currency, $items, $shippingInfo, $shippingAmount, $taxAmount, $discountAmount);
    }

    public function cancelOrder(string $orderId, array $data = []): array
    {
        return $this->client->cancelOrder($orderId, $data);
    }

    /**
     * Calculate total amount including configured fee markup
     */
    public static function calcFees(float $baseAmount): float
    {
        return round($baseAmount * self::$feeRatio, 2);
    }

    /**
     * Calculate fee difference only
     */
    public static function calcFeeDifference(float $baseAmount): float
    {
        return round(self::calcFees($baseAmount) - $baseAmount, 2);
    }

    /**
     * Generate an RFC 4122 compliant UUID v4 using cryptographically secure random bytes.
     * Guaranteed uniqueness across distributed applications.
     */
    public static function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // Version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // RFC 4122 variant

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Generate unique merchant reference order ID using cryptographically secure random bytes.
     * Note: Enforce a UNIQUE constraint in your database (e.g. UNIQUE KEY on `order_reference_id`).
     *
     * @param string $prefix Order prefix (e.g. 'ORD')
     * @return string e.g. "ORD-20261024-A1B2C3D4E5F6"
     */
    public static function generateOrderId(string $prefix = 'ORD'): string
    {
        $secureHex = strtoupper(bin2hex(random_bytes(6)));
        return sprintf('%s-%s-%s', $prefix, date('YmdHis'), $secureHex);
    }

    public static function isSuccessStatus(string $status): bool
    {
        $success = [
            TamaraOrderStateMachine::STATUS_APPROVED,
            TamaraOrderStateMachine::STATUS_AUTHORISED,
            TamaraOrderStateMachine::STATUS_FULLY_CAPTURED,
            'authorized',
            'captured',
            'paid',
        ];
        return in_array(strtolower(trim($status)), $success, true);
    }

    public static function isFailedStatus(string $status): bool
    {
        $failed = [
            TamaraOrderStateMachine::STATUS_DECLINED,
            TamaraOrderStateMachine::STATUS_CANCELLED,
            TamaraOrderStateMachine::STATUS_EXPIRED,
            'canceled',
            'failed',
            'rejected',
        ];
        return in_array(strtolower(trim($status)), $failed, true);
    }

    public static function isTerminalStatus(string $status): bool
    {
        return TamaraOrderStateMachine::isTerminal($status);
    }
}
