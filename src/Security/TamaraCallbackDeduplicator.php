<?php

namespace Tamara\Security;

/**
 * Callable-based deduplicator for easy integration with Laravel Cache, Redis, Memcached, or DB.
 */
class TamaraCallbackDeduplicator implements TamaraDeduplicatorInterface
{
    /** @var callable fn(string $key): bool */
    protected $checkCallback;

    /** @var callable fn(string $key, int $ttl): void */
    protected $storeCallback;

    public function __construct(callable $checkCallback, callable $storeCallback)
    {
        $this->checkCallback = $checkCallback;
        $this->storeCallback = $storeCallback;
    }

    public function isProcessed(string $eventKey): bool
    {
        return (bool) ($this->checkCallback)($eventKey);
    }

    public function markProcessed(string $eventKey, int $ttl = 86400): void
    {
        ($this->storeCallback)($eventKey, $ttl);
    }
}

