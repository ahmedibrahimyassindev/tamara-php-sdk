<?php

namespace Tamara\Contracts;

/**
 * Interface for any order, booking, or cart entity to be payable via Tamara.
 */
interface TamaraOrderableInterface
{
    /**
     * Unique merchant reference ID for this transaction.
     */
    public function getTamaraOrderReferenceId(): string;

    /**
     * The total amount to charge.
     */
    public function getTamaraAmount(): float;

    /**
     * ISO 4217 Currency code (e.g. 'SAR', 'AED').
     */
    public function getTamaraCurrency(): string;

    /**
     * Customer details.
     * Required keys: 'first_name', 'last_name', 'email', 'phone_number'.
     *
     * @return array{first_name: string, last_name: string, email: string, phone_number: string}
     */
    public function getTamaraConsumer(): array;

    /**
     * Line items in the order.
     * Each item should contain: reference_id, name, type, sku, quantity, unit_price, total_amount.
     *
     * @return array[]
     */
    public function getTamaraItems(): array;

    /**
     * Billing / Shipping or installation address.
     *
     * @return array
     */
    public function getTamaraAddress(): array;

    /**
     * Risk assessment metrics (e.g. account creation date, order count).
     *
     * @return array
     */
    public function getTamaraRiskAssessment(): array;

    /**
     * Country Code (e.g. 'SA', 'AE').
     */
    public function getTamaraCountryCode(): string;

    /**
     * Payment type (e.g. 'PAY_BY_INSTALMENTS', 'PAY_BY_LATER').
     */
    public function getTamaraPaymentType(): string;

    /**
     * Callback when a checkout session is successfully created.
     */
    public function onTamaraCheckoutCreated(string $orderId, ?string $checkoutId, ?string $referenceId): void;

    /**
     * Callback when a raw webhook payload is received.
     */
    public function onTamaraRawPayload(string $rawPayload): void;

    // Webhook Events
    public function onTamaraApproved(array $data): void;
    public function onTamaraAuthorised(array $data): void;
    public function onTamaraCaptured(array $data): void;
    public function onTamaraDeclined(array $data): void;
    public function onTamaraCancelled(array $data): void;
    public function onTamaraExpired(array $data): void;
}

