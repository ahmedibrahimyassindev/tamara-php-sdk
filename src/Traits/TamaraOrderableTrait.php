<?php

namespace Tamara\Traits;

use Tamara\TamaraService;

/**
 * Reusable trait that implements basic methods for TamaraOrderableInterface.
 * Can be used in Laravel Eloquent models, Yii2 ActiveRecord, or Doctrine entities.
 */
trait TamaraOrderableTrait
{
    public function getTamaraOrderReferenceId(): string
    {
        return (string) ($this->order_reference_id ?? $this->id);
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
        $amount   = $this->getTamaraAmount();
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

    public function onTamaraCheckoutCreated(string $orderId, ?string $checkoutId, ?string $referenceId): void
    {
        $this->tamara_order_id    = $orderId;
        $this->tamara_checkout_id = $checkoutId;
        $this->tamara_status      = 'new';
        if (method_exists($this, 'save')) {
            $this->save();
        }
    }

    public function onTamaraRawPayload(string $rawPayload): void
    {
        $this->authorisation_data = $rawPayload;
        if (method_exists($this, 'save')) {
            $this->save();
        }
    }

    public function onTamaraApproved(array $data): void
    {
        $this->tamara_status = 'approved';
        if (method_exists($this, 'save')) {
            $this->save();
        }
    }

    public function onTamaraAuthorised(array $data): void
    {
        $this->tamara_status = 'authorised';
        if (method_exists($this, 'save')) {
            $this->save();
        }
    }

    public function onTamaraCaptured(array $data): void
    {
        $this->tamara_status = 'fully_captured';
        if (method_exists($this, 'save')) {
            $this->save();
        }
    }

    public function onTamaraDeclined(array $data): void
    {
        $this->tamara_status = 'declined';
        if (method_exists($this, 'save')) {
            $this->save();
        }
    }

    public function onTamaraCancelled(array $data): void
    {
        $this->tamara_status = 'cancelled';
        if (method_exists($this, 'save')) {
            $this->save();
        }
    }

    public function onTamaraExpired(array $data): void
    {
        $this->tamara_status = 'expired';
        if (method_exists($this, 'save')) {
            $this->save();
        }
    }
}

