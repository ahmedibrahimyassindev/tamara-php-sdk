<?php

namespace Tamara\Tests;

use Tamara\Exceptions\TamaraValidationException;
use Tamara\TamaraClient;
use ReflectionProperty;

class FakeTamaraClient extends TamaraClient
{
    public $calls = [];
    public $orderStatus = 'authorised';
    public $cancelResponseStatus = 200;

    protected function request(string $endpoint, string $method = 'GET', array $data = []): array
    {
        $this->calls[] = [$endpoint, $method, $data];
        if ($method === 'GET') {
            return ['status' => 200, 'body' => [
                'status' => $this->orderStatus,
                'total_amount' => ['amount' => 25.0, 'currency' => 'SAR'],
            ]];
        }
        if (str_ends_with($endpoint, '/cancel')) {
            return ['status' => $this->cancelResponseStatus, 'body' => []];
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
        $this->testConnectTimeoutIsBoundedByRequestTimeout();
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
        testExpect($endpoint === '/payments/capture');
        testExpect($method === 'POST');
        testExpect($data['order_id'] === 'order/1');
        testExpect($data['shipping_amount']['amount'] === 5.0);
        testExpect($data['tax_amount']['amount'] === 2.0);
        testExpect($data['discount_amount']['amount'] === 1.0);

        $client->capturePayment('digital-1', 20.0, 'SAR', [], [
            'shipped_at' => '2026-10-24T12:00:00Z',
            'shipping_company' => 'N/A',
            'tracking_number' => 123456,
            'tracking_url' => null,
        ]);
        testExpect($client->calls[1][2]['shipping_info']['tracking_number'] === 123456);

        try {
            $client->capturePayment('order-2', 10.0);
            testExpect(false, 'Incomplete shipping info must be rejected.');
        } catch (TamaraValidationException $e) {
            testExpect(str_contains($e->getMessage(), 'shipped_at'));
        }
    }

    private function testCancellationRequiresAuthorisedOrder(): void
    {
        $client = new FakeTamaraClient('merchant-token');
        $client->orderStatus = 'approved';
        try {
            $client->cancelOrder('order-1');
            testExpect(false, 'Non-authorised order must not be cancelled.');
        } catch (TamaraValidationException $e) {
            testExpect(str_contains($e->getMessage(), 'authorised'));
        }

        $client->orderStatus = 'authorized';
        $client->cancelOrder('order-2');
        testExpect($client->calls[2][0] === '/orders/order-2/cancel');
        testExpect($client->calls[2][2]['total_amount'] === ['amount' => 25.0, 'currency' => 'SAR']);

        $client->cancelResponseStatus = 409;
        try {
            $client->cancelOrder('order-3', ['total_amount' => ['amount' => 25.0, 'currency' => 'SAR']]);
            testExpect(false, 'A stale cancel precheck must still rely on Tamara final validation.');
        } catch (TamaraValidationException $e) {
            testExpect(str_contains($e->getMessage(), 'not in a cancellable state'));
        }
    }

    private function testConnectTimeoutIsBoundedByRequestTimeout(): void
    {
        $client = new FakeTamaraClient('merchant-token', '', false, null, null, 5, 20);
        $property = new ReflectionProperty(TamaraClient::class, 'connectTimeout');
        testExpect($property->getValue($client) === 5);
    }
}
