<?php

namespace Tamara\Security;

/**
 * In-memory deduplicator (ideal for testing and single-request lifecycle).
 */
class TamaraInMemoryDeduplicator implements TamaraDeduplicatorInterface
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

    public function clear(): void
    {
        $this->processed = [];
    }
}

