<?php

namespace Tamara\Tests;

use Tamara\Contracts\TamaraOrderableInterface;
use Tamara\Security\TamaraInMemoryDeduplicator;
use Tamara\Security\TamaraSignatureVerifier;
use Tamara\TamaraWebhookProcessor;
use Tamara\Traits\TamaraOrderableTrait;

class MockOrder implements TamaraOrderableInterface
{
    use TamaraOrderableTrait;

    public $id = 'ORD-1001';
    public $order_reference_id = 'ORD-1001';
    public $tamara_status = 'new';
    public $eventsReceived = [];
    public $rawPayloadReceived = null;

    public function getTamaraAmount(): float
    {
        return 100.00;
    }

    public function onTamaraRawPayload(string $rawPayload): void
    {
        $this->rawPayloadReceived = $rawPayload;
    }

    public function onTamaraApproved(array $data): void
    {
        $this->eventsReceived[] = 'approved';
        $this->transitionTamaraStatus('approved');
    }

    public function onTamaraAuthorised(array $data): void
    {
        $this->eventsReceived[] = 'authorised';
        $this->transitionTamaraStatus('authorised');
    }

    public function onTamaraCaptured(array $data): void
    {
        $this->eventsReceived[] = 'fully_captured';
        $this->transitionTamaraStatus('fully_captured');
    }

    public function onTamaraCancelled(array $data): void
    {
        $this->eventsReceived[] = 'cancelled';
        $this->transitionTamaraStatus('cancelled');
    }
}

class TamaraWebhookProcessorTest
{
    public function run(): void
    {
        $this->testSignatureEnforcementFailsClosedWithoutToken();
        $this->testSignatureEnforcementFailsClosedWithInvalidSignature();
        $this->testRejectMalformedPayload();
        $this->testUnknownEventRejectedFailClosed();
        $this->testSuccessfulEventProcessing();
        $this->testDeduplicationPreventsDuplicateProcessing();
        $this->testOutofOrderEventRejected();
        $this->testRawPayloadStorageDisabledByDefault();
    }

    protected function testSignatureEnforcementFailsClosedWithoutToken(): void
    {
        $secret = 'test-secret';
        $processor = new TamaraWebhookProcessor();
        $processor->setNotificationToken($secret);

        $payload = json_encode([
            'order_id' => 'ord-1',
            'order_reference_id' => 'ORD-1001',
            'event_type' => 'order_approved',
        ]);

        $res = $processor->process(json_decode($payload, true), $payload, token: null);

        assert($res['success'] === false, 'Must fail closed when token is missing');
        assert(str_contains($res['error'], 'Missing Tamara authentication token'));
    }

    protected function testSignatureEnforcementFailsClosedWithInvalidSignature(): void
    {
        $secret = 'test-secret';
        $processor = new TamaraWebhookProcessor();
        $processor->setNotificationToken($secret);

        $fakeToken = TamaraSignatureVerifier::generateToken(['order_id' => 'ord-1'], 'wrong-secret');

        $payload = json_encode([
            'order_id' => 'ord-1',
            'order_reference_id' => 'ORD-1001',
            'event_type' => 'order_approved',
        ]);

        $res = $processor->process(json_decode($payload, true), $payload, token: $fakeToken);

        assert($res['success'] === false, 'Must fail closed when signature is invalid');
        assert(str_contains($res['error'], 'Invalid webhook authentication'));
    }

    protected function testRejectMalformedPayload(): void
    {
        $secret = 'test-secret';
        $processor = new TamaraWebhookProcessor();
        $processor->setNotificationToken($secret);
        $token = TamaraSignatureVerifier::generateToken(['test' => true], $secret);

        // Missing event_type
        $badPayload = json_encode(['order_id' => '1', 'order_reference_id' => '1']);
        $res = $processor->process(json_decode($badPayload, true), $badPayload, token: $token);
        assert($res['success'] === false);
        assert(str_contains($res['error'], 'event_type'));

        // Invalid JSON
        $res = $processor->process([], '{not: json}', token: $token);
        assert($res['success'] === false);
        assert(str_contains($res['error'], 'Invalid JSON'));
    }

    protected function testUnknownEventRejectedFailClosed(): void
    {
        $secret = 'test-secret';
        $processor = new TamaraWebhookProcessor();
        $processor->setNotificationToken($secret);
        $token = TamaraSignatureVerifier::generateToken(['test' => true], $secret);

        $payload = json_encode([
            'order_id' => 'ord-1',
            'order_reference_id' => 'ORD-1001',
            'event_type' => 'order_unknown_random_event',
        ]);

        $res = $processor->process(json_decode($payload, true), $payload, token: $token);
        assert($res['success'] === false, 'Unknown event must NOT return success');
        assert(str_contains($res['error'], 'Unsupported or unhandled'));
    }

    protected function testSuccessfulEventProcessing(): void
    {
        $secret = 'test-secret';
        $mockOrder = new MockOrder();

        $processor = new TamaraWebhookProcessor();
        $processor->setNotificationToken($secret);
        $processor->addResolver(fn($ref) => $ref === 'ORD-1001' ? $mockOrder : null);

        $token = TamaraSignatureVerifier::generateToken(['order_id' => 'ord-1'], $secret);
        $payload = json_encode([
            'order_id' => 'ord-1',
            'order_reference_id' => 'ORD-1001',
            'event_type' => 'order_approved',
        ]);

        $res = $processor->process(json_decode($payload, true), $payload, token: $token);

        assert($res['success'] === true, 'Valid webhook must succeed');
        assert($mockOrder->tamara_status === 'approved', 'Order status must be updated to approved');
        assert($mockOrder->eventsReceived === ['approved'], 'Callback method must be invoked');
    }

    protected function testDeduplicationPreventsDuplicateProcessing(): void
    {
        $secret = 'test-secret';
        $mockOrder = new MockOrder();

        $dedup = new TamaraInMemoryDeduplicator();
        $processor = new TamaraWebhookProcessor(deduplicator: $dedup);
        $processor->setNotificationToken($secret);
        $processor->addResolver(fn($ref) => $mockOrder);

        $token = TamaraSignatureVerifier::generateToken(['order_id' => 'ord-1'], $secret);
        $payload = json_encode([
            'event_id' => 'evt-999',
            'order_id' => 'ord-1',
            'order_reference_id' => 'ORD-1001',
            'event_type' => 'order_approved',
        ]);

        // First delivery
        $res1 = $processor->process(json_decode($payload, true), $payload, token: $token);
        assert($res1['success'] === true && empty($res1['duplicate']));
        assert(count($mockOrder->eventsReceived) === 1);

        // Second delivery (duplicate retry from Tamara)
        $res2 = $processor->process(json_decode($payload, true), $payload, token: $token);
        assert($res2['success'] === true && $res2['duplicate'] === true, 'Must report duplicate');
        assert(count($mockOrder->eventsReceived) === 1, 'Duplicate must not invoke callback again');
    }

    protected function testOutofOrderEventRejected(): void
    {
        $secret = 'test-secret';
        $mockOrder = new MockOrder();
        $mockOrder->tamara_status = 'fully_captured'; // Already captured!

        $processor = new TamaraWebhookProcessor();
        $processor->setNotificationToken($secret);
        $processor->addResolver(fn($ref) => $mockOrder);

        $token = TamaraSignatureVerifier::generateToken(['order_id' => 'ord-1'], $secret);
        // An old 'order_approved' or 'order_authorised' arrives delayed after capture
        $payload = json_encode([
            'order_id' => 'ord-1',
            'order_reference_id' => 'ORD-1001',
            'event_type' => 'order_approved',
        ]);

        $res = $processor->process(json_decode($payload, true), $payload, token: $token);

        assert($res['ignored'] === true, 'Out of order transition must be ignored');
        assert($mockOrder->tamara_status === 'fully_captured', 'Terminal state must not be overwritten');
    }

    protected function testRawPayloadStorageDisabledByDefault(): void
    {
        $secret = 'test-secret';
        $mockOrder = new MockOrder();

        $processor = new TamaraWebhookProcessor(); // storeRawPayload is false by default
        $processor->setNotificationToken($secret);
        $processor->addResolver(fn($ref) => $mockOrder);

        $token = TamaraSignatureVerifier::generateToken(['order_id' => 'ord-1'], $secret);
        $payload = json_encode([
            'order_id' => 'ord-1',
            'order_reference_id' => 'ORD-1001',
            'event_type' => 'order_approved',
        ]);

        $processor->process(json_decode($payload, true), $payload, token: $token);

        assert($mockOrder->rawPayloadReceived === null, 'Raw payload must NOT be stored by default');
    }
}

