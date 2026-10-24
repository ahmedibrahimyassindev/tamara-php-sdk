<?php

namespace Tamara\Tests;

use Tamara\Exceptions\TamaraValidationException;
use Tamara\TamaraClient;

class FakeTamaraClient extends TamaraClient
{
    public $calls = [];
    public $orderStatus = 'authorised';

    protected function request(string $endpoint, string $method = 'GET', array $data = []): array
    {
        $this->calls[] = [$endpoint, $method, $data];
        if ($method === 'GET') {
            return ['status' => 200, 'body' => ['status' => $this->orderStatus]];
        }
        return ['status' => 200, 'body' => ['status' => 'ok']];
    }
}

class TamaraClientTest
{
    public function run(): void
    {
        $this->testCaptureEndpointPayloadAndShippingValidation();
        $this->testCancellationRequiresAuthorisedOrder();
    }

    private function testCaptureEndpointPayloadAndShippingValidation(): void
    {
        $client = new FakeTamaraClient('merchant-token');
        $shipping = [
            'shipped_at' => '2026-10-24T12:00:00Z',
            'shipping_company' => 'DHL',
            'tracking_number' => 'track-1',
            'tracking_url' => 'https://ship.test/track-1',
        ];
        $client->capturePayment('order/1', 50.0, 'SAR', [], $shipping, 5.0, 2.0, 1.0);
        [$endpoint, $method, $data] = $client->calls[0];
        assert($endpoint === '/payments/capture');
        assert($method === 'POST');
        assert($data['order_id'] === 'order/1');
        assert($data['shipping_amount']['amount'] === 5.0);
        assert($data['tax_amount']['amount'] === 2.0);
        assert($data['discount_amount']['amount'] === 1.0);

        try {
            $client->capturePayment('order-2', 10.0);
            assert(false, 'Incomplete shipping info must be rejected.');
        } catch (TamaraValidationException $e) {
            assert(str_contains($e->getMessage(), 'shipped_at'));
        }
    }

    private function testCancellationRequiresAuthorisedOrder(): void
    {
        $client = new FakeTamaraClient('merchant-token');
        $client->orderStatus = 'approved';
        try {
            $client->cancelOrder('order-1');
            assert(false, 'Non-authorised order must not be cancelled.');
        } catch (TamaraValidationException $e) {
            assert(str_contains($e->getMessage(), 'authorised'));
        }

        $client->orderStatus = 'authorized';
        $client->cancelOrder('order-2');
        assert($client->calls[2][0] === '/orders/order-2/cancel');
    }
}
