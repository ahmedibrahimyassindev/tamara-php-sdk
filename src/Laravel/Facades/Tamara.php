<?php

namespace Tamara\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Tamara\Contracts\TamaraOrderableInterface;
use Tamara\TamaraClient;
use Tamara\TamaraService;
use Tamara\TamaraWebhookProcessor;

/**
 * @method static string initiateCheckout(TamaraOrderableInterface $entity, string $successUrl, string $failureUrl, string $cancelUrl, ?string $notificationUrl = null, ?string $locale = null, string $description = 'Online Order')
 * @method static array authoriseOrder(string $orderId)
 * @method static array getOrder(string $orderId)
 * @method static array capturePayment(string $orderId, float $amount, string $currency = 'SAR', array $items = [], array $shippingInfo = [], float $shippingAmount = 0.0, float $taxAmount = 0.0, float $discountAmount = 0.0)
 * @method static array cancelOrder(string $orderId, array $data = [])
 * @method static TamaraClient getClient()
 * @method static TamaraWebhookProcessor createWebhookProcessor(?\Psr\Log\LoggerInterface $logger = null)
 * @method static float calcFees(float $baseAmount)
 * @method static float calcFeeDifference(float $baseAmount)
 * @method static string generateOrderId(string $prefix = 'ORD')
 * @method static string generateUuidV4()
 * @method static bool isSuccessStatus(string $status)
 * @method static bool isFailedStatus(string $status)
 * @method static bool isTerminalStatus(string $status)
 *
 * @see TamaraService
 */
class Tamara extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'tamara';
    }
}
