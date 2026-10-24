<?php

namespace Tamara\Security;

/**
 * In-memory deduplicator (ideal for testing and single-request lifecycle).
 */
class TamaraInMemoryDeduplicator implements TamaraAtomicDeduplicatorInterface
{
    /** @var array<string, int> Map of eventKey => expiresAt timestamp */
    protected $processed = [];

    public function isProcessed(string $eventKey): bool
    {
        if (!isset($this->processed[$eventKey])) {
            return false;
        }

        if (time() > $this->processed[$eventKey]) {
            unset($this->processed[$eventKey]);
            return false;
        }

        return true;
    }

    public function markProcessed(string $eventKey, int $ttl = 86400): void
    {
        $this->processed[$eventKey] = time() + $ttl;
    }

    public function claim(string $eventKey, int $ttl = 0): bool
    {
        if ($this->isProcessed($eventKey)) {
            return false;
        }
        $this->markProcessed($eventKey, $ttl > 0 ? $ttl : 86400);
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

