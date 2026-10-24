<?php

namespace Tamara\Security;

/** Atomic event reservation for deduplicators backed by shared storage. */
interface TamaraAtomicDeduplicatorInterface extends TamaraDeduplicatorInterface
{
    /** Claim an event key, returning false when another delivery already claimed it. */
    public function claim(string $eventKey, int $ttl = 0): bool;

    /** Release a claim after processing fails so the sender can retry. */
    public function release(string $eventKey): void;
}
