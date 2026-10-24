<?php

namespace Tamara;

use Tamara\Contracts\TamaraOrderableInterface;
use Tamara\Exceptions\TamaraValidationException;
use Tamara\Security\TamaraPaymentValidator;

/**
 * Builds and validates standard Tamara checkout payloads.
 */
class TamaraOrderBuilder
{
    /** @var bool Whether to run strict financial validation on built payload */
    protected $validateAmounts = true;

    public function __construct(bool $validateAmounts = true)
    {
        $this->validateAmounts = $validateAmounts;
    }

    /**
     * Build checkout payload
     *
     * @param TamaraOrderableInterface $entity
     * @param string $successUrl
     * @param string $failureUrl
     * @param string $cancelUrl
     * @param string|null $notificationUrl (Optional webhook url)
     * @param string $locale (e.g. 'ar_SA', 'en_SA')
     * @param string $description (Optional description)
     * @return array
     * @throws TamaraValidationException
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
        $referenceId = trim($entity->getTamaraOrderReferenceId());
        if ($referenceId === '') {
            throw new TamaraValidationException('Order reference ID cannot be empty.');
        }

        $currency = strtoupper(trim($entity->getTamaraCurrency()));
        $amount   = (float) $entity->getTamaraAmount();

        $payload = [
            'order_reference_id' => $referenceId,
            'total_amount'       => [
                'amount'   => $amount,
                'currency' => $currency,
            ],
            'shipping_amount'    => [
                'amount'   => 0.0,
                'currency' => $currency,
            ],
            'tax_amount'         => [
                'amount'   => 0.0,
                'currency' => $currency,
            ],
            'description'        => $description,
            'country_code'       => strtoupper(trim($entity->getTamaraCountryCode())),
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

        if ($this->validateAmounts) {
            TamaraPaymentValidator::validateCheckoutPayload($payload);
        }

        return $payload;
    }

    /**
     * Enable or disable amount validation.
     */
    public function setValidateAmounts(bool $validate): self
    {
        $this->validateAmounts = $validate;
        return $this;
    }

    /**
     * Helper to create formatted locale code (e.g., 'ar_SA' or 'en_AE')
     */
    public static function formatLocale(string $lang = 'ar', string $country = 'SA'): string
    {
        return strtolower($lang) . '_' . strtoupper($country);
    }
}
