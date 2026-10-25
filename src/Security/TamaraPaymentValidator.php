<?php

namespace Tamara\Security;

use Tamara\Exceptions\TamaraValidationException;

/**
 * Validates financial integrity of order and checkout amounts.
 *
 * Checks:
 *  - ISO 4217 currency codes
 *  - Decimal precision according to currency rules (2 decimals for SAR/AED, 3 for KWD/BHD/OMR)
 *  - Positive order total (> 0)
 *  - Non-negative shipping, tax, and discount
 *  - Consistency between items, add-ons/deductions, and total amount
 */
class TamaraPaymentValidator
{
    /** @var string[] Currencies that use 3 decimal places */
    const THREE_DECIMAL_CURRENCIES = ['BHD', 'KWD', 'OMR'];

    /**
     * Validate an entire order checkout payload before submission to Tamara.
     *
     * @param array $payload
     * @throws TamaraValidationException
     */
    public static function validateCheckoutPayload(array $payload): void
    {
        $currency = $payload['total_amount']['currency'] ?? null;
        if (empty($currency) || !is_string($currency)) {
            throw new TamaraValidationException('Payment currency is missing or invalid.');
        }

        self::validateCurrency($currency);

        $totalAmount = $payload['total_amount']['amount'] ?? null;
        if (!is_numeric($totalAmount)) {
            throw new TamaraValidationException('Order total amount is missing or not a numeric value.');
        }
        $totalAmount = (float) $totalAmount;
        self::validateAmount($totalAmount, $currency, 'total_amount', true);

        // Shipping
        $shippingAmount = 0.0;
        if (isset($payload['shipping_amount'])) {
            $shippingAmount = (float) ($payload['shipping_amount']['amount'] ?? 0);
            self::validateAmount($shippingAmount, $currency, 'shipping_amount', false);
        }

        // Tax
        $taxAmount = 0.0;
        if (isset($payload['tax_amount'])) {
            $taxAmount = (float) ($payload['tax_amount']['amount'] ?? 0);
            self::validateAmount($taxAmount, $currency, 'tax_amount', false);
        }

        // Discount
        $discountAmount = 0.0;
        if (isset($payload['discount'])) {
            $discountAmount = (float) ($payload['discount']['amount']['amount'] ?? $payload['discount']['amount'] ?? 0);
            self::validateAmount($discountAmount, $currency, 'discount_amount', false);
        }

        // Validate items if present
        $items = $payload['items'] ?? [];
        if (!empty($items)) {
            if (!is_array($items)) {
                throw new TamaraValidationException('Order items must be an array.');
            }

            $itemsTotal = 0.0;
            foreach ($items as $index => $item) {
                $itemsTotal += self::validateItem($item, $currency, $index);
            }

            // Consistency check: items total + shipping + tax - discount == total amount
            $calculatedTotal = $itemsTotal + $shippingAmount + $taxAmount - $discountAmount;

            if (self::toMinorUnits($calculatedTotal, $currency) !== self::toMinorUnits($totalAmount, $currency)) {
                throw new TamaraValidationException(sprintf(
                    'Order amount mismatch: declared total (%01.2f %s) does not match items (%01.2f) + shipping (%01.2f) + tax (%01.2f) - discount (%01.2f) = calculated (%01.2f).',
                    $totalAmount,
                    $currency,
                    $itemsTotal,
                    $shippingAmount,
                    $taxAmount,
                    $discountAmount,
                    $calculatedTotal
                ));
            }
        }
    }

    /**
     * Validate an ISO 4217 3-character uppercase currency code.
     */
    public static function validateCurrency(string $currency): void
    {
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new TamaraValidationException("Invalid ISO 4217 currency code: [{$currency}]. Currency must be 3 uppercase letters.");
        }
    }

    /**
     * Validate numeric amount and decimal precision.
     *
     * @param float $amount
     * @param string $currency
     * @param string $fieldName
     * @param bool $requirePositive (true for total_amount, false for fees/shipping/tax which can be 0)
     * @throws TamaraValidationException
     */
    public static function validateAmount(
        float $amount,
        string $currency,
        string $fieldName = 'amount',
        bool $requirePositive = false
    ): void {
        if (!is_finite($amount)) {
            throw new TamaraValidationException("Field [{$fieldName}] must be a finite amount.");
        }

        if ($requirePositive && $amount <= 0.0) {
            throw new TamaraValidationException("Field [{$fieldName}] must be strictly positive (> 0). Value given: {$amount}");
        }

        if (!$requirePositive && $amount < 0.0) {
            throw new TamaraValidationException("Field [{$fieldName}] cannot be negative. Value given: {$amount}");
        }

        $maxDecimals = in_array(strtoupper($currency), self::THREE_DECIMAL_CURRENCIES, true) ? 3 : 2;

        // Check decimal precision
        $scaled  = $amount * (10 ** $maxDecimals);
        $rounded = round($scaled);

        if (abs($scaled - $rounded) > 1e-6) {
            throw new TamaraValidationException(
                "Field [{$fieldName}] exceeds allowed precision of {$maxDecimals} decimal places for {$currency}. Value given: {$amount}"
            );
        }
    }

    /**
     * Validate a line item and return its calculated total amount.
     *
     * @param array $item
     * @param string $orderCurrency
     * @param int $index
     * @return float Item total amount
     * @throws TamaraValidationException
     */
    public static function validateItem(array $item, string $orderCurrency, int $index = 0): float
    {
        if (empty($item['name']) || !is_string($item['name'])) {
            throw new TamaraValidationException("Item at index [{$index}] must have a non-empty 'name'.");
        }

        $qty = $item['quantity'] ?? null;
        if (!is_int($qty) && (!is_numeric($qty) || (int) $qty != $qty)) {
            throw new TamaraValidationException("Item at index [{$index}] quantity must be an integer.");
        }
        $qty = (int) $qty;
        if ($qty < 1) {
            throw new TamaraValidationException("Item at index [{$index}] quantity must be at least 1.");
        }

        // Unit price
        $unitPriceData = $item['unit_price'] ?? null;
        if (!is_array($unitPriceData) || !isset($unitPriceData['amount'])) {
            throw new TamaraValidationException("Item at index [{$index}] missing 'unit_price.amount'.");
        }

        $unitPriceCurrency = $unitPriceData['currency'] ?? $orderCurrency;
        if (strtoupper($unitPriceCurrency) !== strtoupper($orderCurrency)) {
            throw new TamaraValidationException("Item at index [{$index}] unit price currency [{$unitPriceCurrency}] does not match order currency [{$orderCurrency}].");
        }

        $unitPrice = (float) $unitPriceData['amount'];
        self::validateAmount($unitPrice, $orderCurrency, "items[{$index}].unit_price", false);

        // Total amount
        $totalPriceData = $item['total_amount'] ?? null;
        if (!is_array($totalPriceData) || !isset($totalPriceData['amount'])) {
            throw new TamaraValidationException("Item at index [{$index}] missing 'total_amount.amount'.");
        }

        $itemTotal = (float) $totalPriceData['amount'];
        self::validateAmount($itemTotal, $orderCurrency, "items[{$index}].total_amount", false);

        // Check line item math (unit_price * quantity - item_discount)
        $itemDiscount = (float) ($item['discount_amount']['amount'] ?? 0.0);
        self::validateAmount($itemDiscount, $orderCurrency, "items[{$index}].discount_amount", false);
        $expectedItemTotal = ($unitPrice * $qty) - $itemDiscount;

        if (self::toMinorUnits($expectedItemTotal, $orderCurrency) !== self::toMinorUnits($itemTotal, $orderCurrency)) {
            throw new TamaraValidationException(sprintf(
                "Item at index [%d] total (%01.2f) does not match unit_price (%01.2f) * quantity (%d) - discount (%01.2f) = %01.2f.",
                $index,
                $itemTotal,
                $unitPrice,
                $qty,
                $itemDiscount,
                $expectedItemTotal
            ));
        }

        return $itemTotal;
    }

    private static function toMinorUnits(float $amount, string $currency): int
    {
        $decimals = in_array(strtoupper($currency), self::THREE_DECIMAL_CURRENCIES, true) ? 3 : 2;
        $scaled = round($amount * (10 ** $decimals));
        if (!is_finite($scaled) || abs($scaled) > PHP_INT_MAX) {
            throw new TamaraValidationException('Amount exceeds the supported financial range.');
        }

        return (int) $scaled;
    }
}

