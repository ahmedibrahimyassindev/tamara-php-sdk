<?php

namespace Tamara\Tests;

use Tamara\TamaraService;

class TamaraOrderReferenceTest
{
    public function run(): void
    {
        $this->testGenerateOrderIdCryptographic();
        $this->testGenerateUuidV4();
    }

    protected function testGenerateOrderIdCryptographic(): void
    {
        $id1 = TamaraService::generateOrderId('TEST');
        $id2 = TamaraService::generateOrderId('TEST');

        assert($id1 !== $id2, 'Consecutive IDs must not collide');
        assert(str_starts_with($id1, 'TEST-'), 'Prefix must be present');
        assert(preg_match('/^TEST-\d{14}-[0-9A-F]{12}$/', $id1) === 1, 'ID must follow secure pattern');
    }

    protected function testGenerateUuidV4(): void
    {
        $uuid1 = TamaraService::generateUuidV4();
        $uuid2 = TamaraService::generateUuidV4();

        assert($uuid1 !== $uuid2, 'UUIDs must not collide');
        // RFC 4122 v4 pattern: 8-4-4-4-12 hex chars with 4 in version position and 8,9,a,b in variant position
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        assert(preg_match($pattern, $uuid1) === 1, "Must be valid RFC 4122 v4 UUID, got {$uuid1}");
    }
}

