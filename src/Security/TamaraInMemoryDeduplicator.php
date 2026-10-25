<?php

namespace TamaraPhpSdk\Security;

/**
 * In-memory deduplicator (ideal for testing and single-request lifecycle).
 */
class TamaraInMemoryDeduplicator implements TamaraAtomicDeduplicatorInterface
{
    /** @var array<string, array{expires:int,state:string}> */
    protected $processed = [];

    public function isProcessed(string $eventKey): bool
    {
        if (!isset($this->processed[$eventKey])) {
            return false;
        }

        if (time() > $this->processed[$eventKey]['expires']) {
            unset($this->processed[$eventKey]);
            return false;
        }

        return $this->processed[$eventKey]['state'] === 'processed';
    }

    public function markProcessed(string $eventKey, int $ttl = 0): void
    {
        $this->processed[$eventKey] = ['expires' => time() + ($ttl > 0 ? $ttl : 86400), 'state' => 'processed'];
    }

    public function claim(string $eventKey, int $ttl = 0): bool
    {
        if (isset($this->processed[$eventKey]) && time() <= $this->processed[$eventKey]['expires']) {
            return false;
        }
        $this->processed[$eventKey] = ['expires' => time() + ($ttl > 0 ? $ttl : 300), 'state' => 'processing'];
        return true;
    }

    public function release(string $eventKey): void
    {
        unset($this->processed[$eventKey]);
    }

    public function clear(): void
    {
        $this->processed = [];
    }
}

