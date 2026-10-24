<?php

namespace Tamara\Tests;

use Tamara\Contracts\TamaraOrderableInterface;
use Tamara\Exceptions\TamaraApiException;
use Tamara\Security\TamaraSignatureVerifier;
use Tamara\TamaraClient;
use Tamara\TamaraService;
use Tamara\Traits\TamaraOrderableTrait;
use RuntimeException;

class ServiceOrder implements TamaraOrderableInterface
{
    use TamaraOrderableTrait;

    public $id = 'service-order';
    public $order_reference_id = 'service-order';
    public $name = 'Service Customer';
    public $email = 'service@example.test';
    public $phone = '+966500000001';
    public $tamara_status = 'new';

    public function getTamaraAmount(): float { return 20.0; }
    public function getTamaraAddress(): array { return ['line1' => '1 Main St', 'city' => 'Riyadh']; }
    public function onTamaraRawPayload(string $rawPayload): void {}
}

class FlakyServiceOrder extends ServiceOrder
{
    public $authorisedAttempts = 0;
    public function onTamaraAuthorised(array $data): void
    {
        $this->authorisedAttempts++;
        if ($this->authorisedAttempts === 1) {
            throw new RuntimeException('temporary database failure');
        }
        parent::onTamaraAuthorised($data);
    }
}

class ServiceAuthoriseClient extends TamaraClient
{
    public $authoriseCalls = 0;
    public $orderStatus = 'authorised';

    public function authoriseOrder(string $orderId): array
    {
        $this->authoriseCalls++;
        throw new TamaraApiException('already authorised', 409);
    }

    public function getOrder(string $orderId): array
    {
        return ['order_id' => $orderId, 'status' => $this->orderStatus];
    }
}

class TamaraServiceTest
{
    public function run(): void
    {
        $this->testAuthoriseTreatsAlreadyAuthorisedAsSuccess();
        $this->testWebhookProcessorAuthoriserWiring();
    }

    private function testAuthoriseTreatsAlreadyAuthorisedAsSuccess(): void
    {
        $client = new ServiceAuthoriseClient('merchant-token', 'notify-token');
        $result = (new TamaraService($client))->authoriseOrder('order-1');
        testExpect($result['status'] === 'authorised');
        testExpect($client->authoriseCalls === 1);

        $client->orderStatus = 'approved';
        try {
            (new TamaraService($client))->authoriseOrder('order-2');
            testExpect(false, 'An order that remains approved must preserve the API failure.');
        } catch (TamaraApiException $e) {
            testExpect($e->getHttpStatusCode() === 409);
        }
    }

    private function testWebhookProcessorAuthoriserWiring(): void
    {
        $client = new ServiceAuthoriseClient('merchant-token', 'notify-token');
        $service = new TamaraService($client);
        $processor = $service->createWebhookProcessor()->autoAuthorise();
        $order = new ServiceOrder();
        $authorisedHandlerCalls = 0;
        $processor->on('order_authorised', function ($entity, array $data) use (&$authorisedHandlerCalls): void {
            $authorisedHandlerCalls++;
            $entity->onTamaraAuthorised($data);
        });
        $processor->addResolver(fn($reference) => $order);
        $data = ['order_id' => 'tamara-1', 'order_reference_id' => 'service-order', 'event_type' => 'order_approved'];
        $raw = json_encode($data);
        $token = TamaraSignatureVerifier::generateToken(['iat' => time()], 'notify-token');

        $result = $processor->process($data, $raw, token: $token);
        testExpect($result['success'] === true);
        testExpect($order->getTamaraStatus() === 'authorised');
        testExpect($client->authoriseCalls === 1);
        testExpect($authorisedHandlerCalls === 1);

        $retryClient = new ServiceAuthoriseClient('merchant-token', 'notify-token');
        $retryService = new TamaraService($retryClient);
        $retryProcessor = $retryService->createWebhookProcessor()->autoAuthorise();
        $flakyOrder = new FlakyServiceOrder();
        $retryProcessor->addResolver(fn($reference) => $flakyOrder);
        $first = $retryProcessor->process($data, $raw, token: $token);
        testExpect($first['http_status'] === 500);
        $second = $retryProcessor->process($data, $raw, token: $token);
        testExpect($second['success'] === true);
        testExpect($flakyOrder->getTamaraStatus() === 'authorised');
        testExpect($retryClient->authoriseCalls === 2);
    }
}
