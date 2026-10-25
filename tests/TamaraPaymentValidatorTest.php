<?php

namespace TamaraPhpSdk\Tests;

use TamaraPhpSdk\Exceptions\TamaraValidationException;
use TamaraPhpSdk\Security\TamaraPaymentValidator;

class TamaraPaymentValidatorTest
{
    public function run(): void
    {
        $this->testValidOrderPayload();
        $this->testNegativeAmountRejected();
        $this->testZeroAmountRejected();
        $this->testExcessiveDecimalsRejected();
        $this->testInvalidCurrencyRejected();
        $this->testAmountArithmeticMismatchRejected();
        $this->testItemArithmeticMismatchRejected();
        $this->testDiscountArithmetic();
        $this->testThreeDecimalCurrencyArithmeticIsExact();
    }

    protected function testValidOrderPayload(): void
    {
        $payload = [
            'total_amount' => ['amount' => 100.00, 'currency' => 'SAR'],
            'shipping_amount' => ['amount' => 10.00, 'currency' => 'SAR'],
            'tax_amount' => ['amount' => 15.00, 'currency' => 'SAR'],
            'items' => [
                [
                    'name' => 'Book',
                    'quantity' => 1,
                    'unit_price' => ['amount' => 75.00, 'currency' => 'SAR'],
                    'total_amount' => ['amount' => 75.00, 'currency' => 'SAR'],
                ]
            ],
        ];

        // 75 (items) + 10 (shipping) + 15 (tax) = 100.00
        TamaraPaymentValidator::validateCheckoutPayload($payload);
        testExpect(true, 'Valid payload must pass');
    }

    protected function testNegativeAmountRejected(): void
    {
        $caught = false;
        try {
            TamaraPaymentValidator::validateAmount(-50.0, 'SAR', 'total_amount', true);
        } catch (TamaraValidationException $e) {
            $caught = true;
        }
        testExpect($caught, 'Negative amount must be rejected');
    }

    protected function testZeroAmountRejected(): void
    {
        $caught = false;
        try {
            TamaraPaymentValidator::validateAmount(0.0, 'SAR', 'total_amount', true);
        } catch (TamaraValidationException $e) {
            $caught = true;
        }
        testExpect($caught, 'Zero total amount must be rejected');
    }

    protected function testExcessiveDecimalsRejected(): void
    {
        $caught = false;
        try {
            // SAR supports max 2 decimals, 10.125 has 3
            TamaraPaymentValidator::validateAmount(10.125, 'SAR', 'total_amount', true);
        } catch (TamaraValidationException $e) {
            $caught = true;
            testExpect(str_contains($e->getMessage(), 'decimal places'));
        }
        testExpect($caught, 'Excessive decimal precision must be rejected');
    }

    protected function testInvalidCurrencyRejected(): void
    {
        $caught = false;
        try {
            TamaraPaymentValidator::validateCurrency('sar'); // Lowercase
        } catch (TamaraValidationException $e) {
            $caught = true;
        }
        testExpect($caught, 'Lowercase currency code must be rejected');

        $caught = false;
        try {
            TamaraPaymentValidator::validateCurrency('SA'); // 2 letters
        } catch (TamaraValidationException $e) {
            $caught = true;
        }
        testExpect($caught, 'Invalid currency code length must be rejected');
    }

    protected function testAmountArithmeticMismatchRejected(): void
    {
        $payload = [
            'total_amount' => ['amount' => 200.00, 'currency' => 'SAR'], // Mismatch!
            'shipping_amount' => ['amount' => 0.0, 'currency' => 'SAR'],
            'tax_amount' => ['amount' => 0.0, 'currency' => 'SAR'],
            'items' => [
                [
                    'name' => 'Shirt',
                    'quantity' => 1,
                    'unit_price' => ['amount' => 50.00, 'currency' => 'SAR'],
                    'total_amount' => ['amount' => 50.00, 'currency' => 'SAR'],
                ]
            ],
        ];

        $caught = false;
        try {
            TamaraPaymentValidator::validateCheckoutPayload($payload);
        } catch (TamaraValidationException $e) {
            $caught = true;
            testExpect(str_contains($e->getMessage(), 'Order amount mismatch'));
        }
        testExpect($caught, 'Amount mismatch must be rejected');
    }

    protected function testItemArithmeticMismatchRejected(): void
    {
        $item = [
            'name' => 'Shoes',
            'quantity' => 2,
            'unit_price' => ['amount' => 50.00, 'currency' => 'SAR'],
            'total_amount' => ['amount' => 150.00, 'currency' => 'SAR'], // 2 * 50 != 150
        ];

        $caught = false;
        try {
            TamaraPaymentValidator::validateItem($item, 'SAR', 0);
        } catch (TamaraValidationException $e) {
            $caught = true;
        }
        testExpect($caught, 'Item math discrepancy must be rejected');
    }

    protected function testDiscountArithmetic(): void
    {
        TamaraPaymentValidator::validateCheckoutPayload([
            'total_amount' => ['amount' => 95.0, 'currency' => 'SAR'],
            'discount' => ['name' => 'Promo', 'amount' => ['amount' => 5.0, 'currency' => 'SAR']],
            'items' => [[
                'name' => 'Item',
                'quantity' => 1,
                'unit_price' => ['amount' => 100.0, 'currency' => 'SAR'],
                'total_amount' => ['amount' => 100.0, 'currency' => 'SAR'],
            ]],
        ]);
        testExpect(true, 'Discount arithmetic must be accepted.');
    }

    protected function testThreeDecimalCurrencyArithmeticIsExact(): void
    {
        TamaraPaymentValidator::validateCheckoutPayload([
            'total_amount' => ['amount' => 10.123, 'currency' => 'KWD'],
            'shipping_amount' => ['amount' => 0.0, 'currency' => 'KWD'],
            'tax_amount' => ['amount' => 0.0, 'currency' => 'KWD'],
            'items' => [[
                'name' => 'KWD item', 'quantity' => 1,
                'unit_price' => ['amount' => 10.123, 'currency' => 'KWD'],
                'total_amount' => ['amount' => 10.123, 'currency' => 'KWD'],
            ]],
        ]);

        $payload = [
            'total_amount' => ['amount' => 10.009, 'currency' => 'BHD'],
            'shipping_amount' => ['amount' => 0.0, 'currency' => 'BHD'],
            'tax_amount' => ['amount' => 0.0, 'currency' => 'BHD'],
            'items' => [[
                'name' => 'BHD item', 'quantity' => 1,
                'unit_price' => ['amount' => 10.000, 'currency' => 'BHD'],
                'total_amount' => ['amount' => 10.000, 'currency' => 'BHD'],
            ]],
        ];
        try {
            TamaraPaymentValidator::validateCheckoutPayload($payload);
            testExpect(false, 'A 0.009 BHD mismatch must not fit inside a two-decimal epsilon.');
        } catch (TamaraValidationException $e) {
            testExpect(str_contains($e->getMessage(), 'Order amount mismatch'));
        }
    }
}

