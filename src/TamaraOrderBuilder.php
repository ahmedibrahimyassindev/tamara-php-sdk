<?php

namespace Tamara;

use Tamara\Contracts\TamaraOrderableInterface;

/**
 * Builds standard Tamara checkout payload from a generic orderable object.
 */
class TamaraOrderBuilder
{
    /**
     * Build checkout payload
     *
     * @param TamaraOrderableInterface $entity
     * @param string $successUrl
     * @param string $failureUrl
     * @param string $cancelUrl
     * @param string $notificationUrl (Optional webhook url)
     * @param string $locale (e.g. 'ar_SA', 'en_SA')
     * @param string $description (Optional description)
     * @return array
     */
    public function build(
        TamaraOrderableInterface $entity,
        string $successUrl,
        string $failureUrl,
        string $cancelUrl,
        ?string $notificationUrl = null,
        string $locale = 'ar_SA',
        string $description = 'Online Order'
    ): array {
        $currency = $entity->getTamaraCurrency();
        $amount   = $entity->getTamaraAmount();

        $payload = [
            'order_reference_id' => $entity->getTamaraOrderReferenceId(),
            'total_amount'       => [
                'amount'   => $amount,
                'currency' => $currency,
            ],
            'shipping_amount'    => [
                'amount'   => 0,
                'currency' => $currency,
            ],
            'tax_amount'         => [
                'amount'   => 0,
                'currency' => $currency,
            ],
            'description'        => $description,
            'country_code'       => $entity->getTamaraCountryCode(),
            'payment_type'       => $entity->getTamaraPaymentType(),
            'items'              => $entity->getTamaraItems(),
            'locale'             => $locale,
            'consumer'           => $entity->getTamaraConsumer(),
            'merchant_url'       => [
                'success' => $successUrl,
                'failure' => $failureUrl,
                'cancel'  => $cancelUrl,
            ],
            'risk_assessment'    => $entity->getTamaraRiskAssessment(),
        ];

        if ($notificationUrl !== null) {
            $payload['merchant_url']['notification'] = $notificationUrl;
        }

        $address = $entity->getTamaraAddress();
        if (!empty($address)) {
            $payload['billing_address']  = $address;
            $payload['shipping_address'] = $address;
        }

        return $payload;
    }

    /**
     * Helper to create formatted locale code (e.g., 'ar_SA' or 'en_AE')
     */
    public static function formatLocale(string $lang = 'ar', string $country = 'SA'): string
    {
        return strtolower($lang) . '_' . strtoupper($country);
    }
}

