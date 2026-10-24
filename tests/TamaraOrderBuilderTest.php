<?php

namespace Tamara\Tests;

use Tamara\Contracts\TamaraOrderableInterface;
use Tamara\Exceptions\TamaraValidationException;
use Tamara\TamaraOrderBuilder;
use Tamara\Traits\TamaraOrderableTrait;

class BuilderOrder implements TamaraOrderableInterface
{
    use TamaraOrderableTrait;

    public $id = 'order-1';
    public $order_reference_id = 'order-1';
    public $name = 'Single';
    public $email = 'single@example.test';
    public $phone = '+966500000000';

    public function getTamaraAmount(): float { return 100.0; }
    public function getTamaraAddress(): array { return ['line1' => '1 Main St', 'city' => 'Riyadh']; }
    public function onTamaraRawPayload(string $rawPayload): void {}
}

class DigitalBuilderOrder extends BuilderOrder
{
    public function getTamaraAddress(): array { return []; }
}

class TamaraOrderBuilderTest
{
    public function run(): void
    {
        $this->testDefaultFinancialFieldsAndOptionalRiskData();
        $this->testDiscountRequiresPositiveAmountAndHasName();
        $this->testDigitalCheckoutCanOmitAddressWhenConfigured();
        $this->testSingleNameCustomerGetsValidLastName();
    }

    private function build(TamaraOrderableInterface $order, ?TamaraOrderBuilder $builder = null): array
    {
        return ($builder ?? new TamaraOrderBuilder())->build($order, 'https://shop.test/s', 'https://shop.test/f', 'https://shop.test/c');
    }

    private function testDefaultFinancialFieldsAndOptionalRiskData(): void
    {
        $payload = $this->build(new BuilderOrder());
        testExpect($payload['shipping_amount'] === ['amount' => 0.0, 'currency' => 'SAR']);
        testExpect($payload['tax_amount'] === ['amount' => 0.0, 'currency' => 'SAR']);
        testExpect(!array_key_exists('risk_assessment', $payload));
        testExpect(!array_key_exists('discount', $payload));
    }

    private function testDiscountRequiresPositiveAmountAndHasName(): void
    {
        $order = new class extends BuilderOrder {
            public function getTamaraAmount(): float { return 95.0; }
            public function getTamaraDiscount(): float { return 5.0; }
            public function getTamaraItems(): array
            {
                $item = parent::getTamaraItems()[0];
                $item['unit_price']['amount'] = 100.0;
                $item['total_amount']['amount'] = 100.0;
                return [$item];
            }
        };
        $payload = $this->build($order);
        testExpect($payload['discount']['name'] === 'Discount');
        testExpect($payload['discount']['amount'] === ['amount' => 5.0, 'currency' => 'SAR']);
    }

    private function testDigitalCheckoutCanOmitAddressWhenConfigured(): void
    {
        $builder = (new TamaraOrderBuilder())->setRequiresShippingAddress(false);
        $payload = $this->build(new DigitalBuilderOrder(), $builder);
        testExpect(!array_key_exists('shipping_address', $payload));
        testExpect(!array_key_exists('billing_address', $payload));

        try {
            $this->build(new DigitalBuilderOrder());
            testExpect(false, 'Shipping address should be required by default.');
        } catch (TamaraValidationException $e) {
            testExpect(str_contains($e->getMessage(), 'address'));
        }
    }

    private function testSingleNameCustomerGetsValidLastName(): void
    {
        $consumer = (new BuilderOrder())->getTamaraConsumer();
        testExpect($consumer['first_name'] === 'Single');
        testExpect($consumer['last_name'] === 'Single');
    }
}
