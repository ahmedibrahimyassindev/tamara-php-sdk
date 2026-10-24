<?php

namespace Tamara\Tests;

use Tamara\TamaraService;
use Tamara\Exceptions\TamaraValidationException;
use Tamara\Traits\TamaraOrderableTrait;

class TamaraOrderReferenceTest
{
    public function run(): void
    {
        $this->testGenerateOrderIdCryptographic();
        $this->testGenerateUuidV4();
        $this->testMissingReferenceFails();
    }

    protected function testGenerateOrderIdCryptographic(): void
    {
        $id1 = TamaraService::generateOrderId('TEST');
        $id2 = TamaraService::generateOrderId('TEST');

        testExpect($id1 !== $id2, 'Consecutive IDs must not collide');
        testExpect(str_starts_with($id1, 'TEST-'), 'Prefix must be present');
        testExpect(preg_match('/^TEST-\d{14}-[0-9A-F]{12}$/', $id1) === 1, 'ID must follow secure pattern');
    }

    protected function testGenerateUuidV4(): void
    {
        $uuid1 = TamaraService::generateUuidV4();
        $uuid2 = TamaraService::generateUuidV4();

        testExpect($uuid1 !== $uuid2, 'UUIDs must not collide');
        // RFC 4122 v4 pattern: 8-4-4-4-12 hex chars with 4 in version position and 8,9,a,b in variant position
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        testExpect(preg_match($pattern, $uuid1) === 1, "Must be valid RFC 4122 v4 UUID, got {$uuid1}");
    }

    protected function testMissingReferenceFails(): void
    {
        $order = new class {
            use TamaraOrderableTrait;
        };
        try {
            $order->getTamaraOrderReferenceId();
            testExpect(false, 'Missing stable order reference must fail.');
        } catch (TamaraValidationException $e) {
            testExpect(str_contains($e->getMessage(), 'stable order_reference_id'));
        }
    }
}

