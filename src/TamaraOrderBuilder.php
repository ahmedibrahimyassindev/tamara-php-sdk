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
    protected $requiresShippingAddress = true;

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
        ];

        $riskAssessment = $entity->getTamaraRiskAssessment();
        if ($riskAssessment !== []) {
            $payload['risk_assessment'] = $riskAssessment;
        }

        foreach (['ShippingAmount' => 'shipping_amount', 'TaxAmount' => 'tax_amount'] as $method => $key) {
            $getter = 'getTamara' . $method;
            $payload[$key] = [
                'amount' => method_exists($entity, $getter) ? (float) $entity->$getter() : 0.0,
                'currency' => $currency,
            ];
        }
        if (method_exists($entity, 'getTamaraDiscount')) {
            $discount = (float) $entity->getTamaraDiscount();
            if ($discount > 0) {
                $payload['discount'] = [
                    'name' => method_exists($entity, 'getTamaraDiscountName') ? (string) $entity->getTamaraDiscountName() : 'Discount',
                    'amount' => ['amount' => $discount, 'currency' => $currency],
                ];
            }
        }

        if ($notificationUrl !== null) {
            $payload['merchant_url']['notification'] = $notificationUrl;
        }

        $address = $entity->getTamaraAddress();
        if (empty($address) && $this->requiresShippingAddress) {
            throw new TamaraValidationException('A billing and shipping address is required for Tamara checkout.');
        }
        if (!empty($address)) {
            $payload['billing_address'] = $address;
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

    public function setRequiresShippingAddress(bool $required): self
    {
        $this->requiresShippingAddress = $required;
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
