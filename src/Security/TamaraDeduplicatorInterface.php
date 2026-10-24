<?php

namespace Tamara\Security;

/**
 * Interface for deduplicating incoming webhook events.
 */
interface TamaraDeduplicatorInterface
{
    /**
     * Check if the event key has already been processed.
     *
     * @param string $eventKey
     * @return bool
     */
    public function isProcessed(string $eventKey): bool;

    /**
     * Mark an event key as processed.
     *
     * @param string $eventKey
     * @param int $ttl Time to keep deduplication record in seconds (default 86400 = 24h)
     * @return void
     */
    public function markProcessed(string $eventKey, int $ttl = 0): void;
}

