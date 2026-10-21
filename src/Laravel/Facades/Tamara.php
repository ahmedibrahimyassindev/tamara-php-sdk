<?php

namespace Tamara\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Tamara\Contracts\TamaraOrderableInterface;
use Tamara\TamaraClient;
use Tamara\TamaraService;

/**
 * @method static string initiateCheckout(TamaraOrderableInterface $entity, string $successUrl, string $failureUrl, string $cancelUrl, ?string $notificationUrl = null, string $locale = 'ar_SA', string $description = 'Online Order')
 * @method static array authoriseOrder(string $orderId)
 * @method static array getOrder(string $orderId)
 * @method static array capturePayment(string $orderId, float $amount, string $currency = 'SAR', array $items = [])
 * @method static array cancelOrder(string $orderId)
 * @method static TamaraClient getClient()
 * @method static float calcFees(float $baseAmount)
 * @method static string generateOrderId(string $prefix = 'ORD')
 * @method static bool isSuccessStatus(string $status)
 * @method static bool isFailedStatus(string $status)
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

