<?php

namespace Tamara;

use Psr\Log\LoggerInterface;
use Tamara\Contracts\TamaraOrderableInterface;

/**
 * High-level Tamara Service Orchestrator
 */
class TamaraService
{
    /** @var TamaraClient */
    protected $client;

    /** @var TamaraOrderBuilder */
    protected $builder;

    /** @var float Gateway markup/fee ratio (configurable, e.g. 1.076345) */
    protected static $feeRatio = 1.076345;

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
     *
     * @param LoggerInterface|null $logger
     * @return self
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

        $entity->onTamaraCheckoutCreated(
            $result['order_id'] ?? '',
            $result['checkout_id'] ?? null,
            $entity->getTamaraOrderReferenceId()
        );

        return $result['checkout_url'];
    }

    public function getClient(): TamaraClient
    {
        return $this->client;
    }

    public function authoriseOrder(string $orderId): array
    {
        return $this->client->authoriseOrder($orderId);
    }

    public function getOrder(string $orderId): array
    {
        return $this->client->getOrder($orderId);
    }

    public function capturePayment(string $orderId, float $amount, string $currency = 'SAR', array $items = []): array
    {
        return $this->client->capturePayment($orderId, $amount, $currency, $items);
    }

    public function cancelOrder(string $orderId): array
    {
        return $this->client->cancelOrder($orderId);
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
     * Generate unique reference order ID (RFC4122 v4 UUID or alphanumeric unique)
     */
    public static function generateOrderId(string $prefix = 'ORD'): string
    {
        return sprintf(
            '%s-%s-%s-%s',
            $prefix,
            date('Ymd'),
            substr(uniqid(), -6),
            rand(1000, 9999)
        );
    }

    public static function isSuccessStatus(string $status): bool
    {
        $success = ['approved', 'authorised', 'authorized', 'captured', 'paid', 'fully_captured'];
        return in_array(strtolower($status), $success, true);
    }

    public static function isFailedStatus(string $status): bool
    {
        $failed = ['failed', 'declined', 'cancelled', 'canceled', 'expired', 'rejected'];
        return in_array(strtolower($status), $failed, true);
    }
}

