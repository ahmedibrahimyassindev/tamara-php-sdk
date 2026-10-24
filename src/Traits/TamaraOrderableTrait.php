<?php

namespace Tamara\Traits;

use Tamara\Security\TamaraOrderStateMachine;
use Tamara\Security\TamaraSanitizer;
use Tamara\TamaraService;

/**
 * Reusable trait that implements secure methods for TamaraOrderableInterface.
 * Can be used in Laravel Eloquent models, Yii2 ActiveRecord, or Doctrine entities.
 */
trait TamaraOrderableTrait
{
    public function getTamaraOrderReferenceId(): string
    {
        if (!empty($this->order_reference_id)) {
            return (string) $this->order_reference_id;
        }

        if (!empty($this->id)) {
            return (string) $this->id;
        }

        // Generate cryptographically secure reference ID
        return TamaraService::generateOrderId('ORD');
    }

    public function getTamaraCurrency(): string
    {
        return $this->currency ?? 'SAR';
    }

    public function getTamaraCountryCode(): string
    {
        return $this->country_code ?? 'SA';
    }

    public function getTamaraPaymentType(): string
    {
        return 'PAY_BY_INSTALMENTS';
    }

    public function getTamaraConsumer(): array
    {
        $fullName = (string) ($this->customer_name ?? $this->name ?? $this->full_name ?? '');
        $parts    = explode(' ', trim($fullName), 2);

        return [
            'first_name'   => !empty($parts[0]) ? $parts[0] : 'Customer',
            'last_name'    => !empty($parts[1]) ? $parts[1] : '-',
            'email'        => (string) ($this->customer_email ?? $this->email ?? ''),
            'phone_number' => (string) ($this->customer_phone ?? $this->phone ?? ''),
        ];
    }

    public function getTamaraItems(): array
    {
        $amount   = (float) $this->getTamaraAmount();
        $currency = $this->getTamaraCurrency();

        return [
            [
                'reference_id' => (string) ($this->id ?? 'ITEM-1'),
                'name'         => 'Order Item',
                'type'         => 'Physical',
                'sku'          => 'SKU-' . ($this->id ?? '1'),
                'quantity'     => 1,
                'unit_price'   => ['amount' => $amount, 'currency' => $currency],
                'total_amount' => ['amount' => $amount, 'currency' => $currency],
            ]
        ];
    }

    public function getTamaraAddress(): array
    {
        return [];
    }

    public function getTamaraRiskAssessment(): array
    {
        return [
            'account_creation_date' => date('d-m-Y'),
            'total_order_count'     => 1,
        ];
    }

    public function getTamaraStatus(): ?string
    {
        return isset($this->tamara_status) ? (string) $this->tamara_status : null;
    }

    /**
     * Safely transition to a new status using the state machine.
     * Prevents out-of-order events from overwriting newer or terminal states.
     *
     * @param string $newStatus
     * @return bool True if transition was applied, false if rejected as out-of-order
     */
    protected function transitionTamaraStatus(string $newStatus): bool
    {
        $currentStatus = $this->getTamaraStatus();

        if (!TamaraOrderStateMachine::canTransition($currentStatus, $newStatus)) {
            return false;
        }

        $this->tamara_status = TamaraOrderStateMachine::normalizeStatus($newStatus);

        if (method_exists($this, 'save')) {
            $this->save();
        }

        return true;
    }

    public function onTamaraCheckoutCreated(string $orderId, ?string $checkoutId, ?string $referenceId): void
    {
        $this->tamara_order_id    = $orderId;
        $this->tamara_checkout_id = $checkoutId;
        $this->transitionTamaraStatus(TamaraOrderStateMachine::STATUS_NEW);
    }

    /**
     * Handle raw webhook payload storage.
     * Redacts sensitive data before persisting to prevent PII or credential leaks.
     */
    public function onTamaraRawPayload(string $rawPayload): void
    {
        // Store sanitized payload to comply with privacy and PCI data protection
        if (property_exists($this, 'authorisation_data') || isset($this->authorisation_data)) {
            $this->authorisation_data = TamaraSanitizer::sanitizeJson($rawPayload);
            if (method_exists($this, 'save')) {
                $this->save();
            }
        }
    }

    public function onTamaraApproved(array $data): void
    {
        $this->transitionTamaraStatus(TamaraOrderStateMachine::STATUS_APPROVED);
    }

    public function onTamaraAuthorised(array $data): void
    {
        $this->transitionTamaraStatus(TamaraOrderStateMachine::STATUS_AUTHORISED);
    }

    public function onTamaraCaptured(array $data): void
    {
        $this->transitionTamaraStatus(TamaraOrderStateMachine::STATUS_FULLY_CAPTURED);
    }

    public function onTamaraDeclined(array $data): void
    {
        $this->transitionTamaraStatus(TamaraOrderStateMachine::STATUS_DECLINED);
    }

    public function onTamaraCancelled(array $data): void
    {
        $this->transitionTamaraStatus(TamaraOrderStateMachine::STATUS_CANCELLED);
    }

    public function onTamaraExpired(array $data): void
    {
        $this->transitionTamaraStatus(TamaraOrderStateMachine::STATUS_EXPIRED);
    }

    public function onTamaraRefunded(array $data): void
    {
        $this->transitionTamaraStatus(TamaraOrderStateMachine::STATUS_REFUNDED);
    }
}
