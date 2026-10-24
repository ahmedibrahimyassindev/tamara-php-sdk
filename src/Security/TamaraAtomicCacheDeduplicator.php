<?php

namespace Tamara\Security;

/**
 * Adapter for cache repositories that provide atomic add, including Laravel Cache.
 * The cache object is intentionally untyped so the core SDK stays framework agnostic.
 */
class TamaraAtomicCacheDeduplicator implements TamaraAtomicDeduplicatorInterface
{
    protected $cache;
    protected $prefix;
    protected $ttl;

    public function __construct(object $cache, int $ttl = 86400, string $prefix = 'tamara:webhook:')
    {
        foreach (['has', 'add', 'put', 'forget'] as $method) {
            if (!method_exists($cache, $method)) {
                throw new \InvalidArgumentException("Cache repository must implement {$method}().");
            }
        }
        $this->cache = $cache;
        $this->ttl = max(1, $ttl);
        $this->prefix = $prefix;
    }

    public function isProcessed(string $eventKey): bool
    {
        return (bool) $this->cache->has($this->key($eventKey));
    }

    public function markProcessed(string $eventKey, int $ttl = 86400): void
    {
        $this->cache->put($this->key($eventKey), 1, max(1, $ttl ?: $this->ttl));
    }

    public function claim(string $eventKey, int $ttl = 86400): bool
    {
        return (bool) $this->cache->add($this->key($eventKey), 1, max(1, $ttl > 0 ? $ttl : $this->ttl));
    }

    public function release(string $eventKey): void
    {
        $this->cache->forget($this->key($eventKey));
    }

    protected function key(string $eventKey): string
    {
        return $this->prefix . hash('sha256', $eventKey);
    }
}
