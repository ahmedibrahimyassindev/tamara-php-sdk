<?php

namespace Tamara\Tests;

use Tamara\Contracts\TamaraOrderableInterface;
use Tamara\Exceptions\TamaraApiException;
use Tamara\Security\TamaraSignatureVerifier;
use Tamara\TamaraClient;
use Tamara\TamaraService;
use Tamara\Traits\TamaraOrderableTrait;
use Psr\Log\NullLogger;
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

class CheckoutTrackingClient extends TamaraClient
{
    public $checkoutCalls = 0;
    public function createCheckout(array $payload): array
    {
        $this->checkoutCalls++;
        return ['order_id' => 'tamara-order', 'checkout_id' => 'checkout', 'checkout_url' => 'https://checkout.test'];
    }
}

class CapturingLogger extends NullLogger
{
    public $errors = [];
    public function error($message, array $context = []): void { $this->errors[] = [$message, $context]; }
}

class TamaraServiceTest
{
    public function run(): void
    {
        $this->testAuthoriseTreatsAlreadyAuthorisedAsSuccess();
        $this->testWebhookProcessorAuthoriserWiring();
        $this->testCheckoutPersistenceIsCheckedBeforeRemoteCall();
        $this->testCheckoutPersistenceFailureIsLogged();
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

    private function testCheckoutPersistenceIsCheckedBeforeRemoteCall(): void
    {
        $client = new CheckoutTrackingClient('merchant-token', 'notify-token');
        $order = new class implements TamaraOrderableInterface {
            use TamaraOrderableTrait;
            public $order_reference_id = 'plain-order';
            public $tamara_order_id;
            public $tamara_checkout_id;
            public function getTamaraAmount(): float { return 20.0; }
            public function getTamaraAddress(): array { return ['line1' => '1 Main St', 'city' => 'Riyadh']; }
            public function onTamaraRawPayload(string $rawPayload): void {}
        };
        try {
            (new TamaraService($client))->initiateCheckout($order, 'https://shop.test/s', 'https://shop.test/f', 'https://shop.test/c');
            testExpect(false, 'Checkout should fail before remote creation when identifiers cannot be stored.');
        } catch (\Tamara\Exceptions\TamaraValidationException $e) {
            testExpect($client->checkoutCalls === 0);
            testExpect(str_contains($e->getMessage(), 'tamara_status'));
        }
    }

    private function testCheckoutPersistenceFailureIsLogged(): void
    {
        $logger = new CapturingLogger();
        $client = new CheckoutTrackingClient('merchant-token', 'notify-token', false, null, $logger);
        $order = new class extends ServiceOrder {
            public $tamara_order_id;
            public $tamara_checkout_id;
            public function save(): void { throw new RuntimeException('database unavailable'); }
        };

        try {
            (new TamaraService($client))->initiateCheckout($order, 'https://shop.test/s', 'https://shop.test/f', 'https://shop.test/c');
            testExpect(false, 'Local persistence failure should propagate.');
        } catch (RuntimeException $e) {
            testExpect($e->getMessage() === 'database unavailable');
        }

        testExpect($client->checkoutCalls === 1, 'Remote checkout should have been created before persistence failed.');
        testExpect(count($logger->errors) === 1);
        testExpect($logger->errors[0][1]['order_id'] === 'tamara-order');
        testExpect($logger->errors[0][1]['checkout_id'] === 'checkout');
    }
}
