<?php

namespace Tamara\Tests;

use ArrayAccess;
use ReflectionProperty;
use Tamara\Laravel\TamaraServiceProvider;
use Tamara\TamaraOrderBuilder;
use Tamara\TamaraClient;
use Tamara\TamaraService;
use Tamara\TamaraWebhookProcessor;
use Tamara\Security\TamaraAtomicCacheDeduplicator;

class FakeLaravelConfig
{
    private $values;
    public function __construct(array $values) { $this->values = $values; }
    public function get(string $key, $default = null) { return $this->values[$key] ?? $default; }
    public function set(string $key, $value): void { $this->values[$key] = $value; }
}

class FakeLaravelApp implements ArrayAccess
{
    public $config;
    public $cache;
    private $bindings = [];
    public function __construct(array $config)
    {
        $this->config = new FakeLaravelConfig(['tamara' => $config]);
        $this->cache = new FakeLaravelCacheManager();
    }
    public function offsetExists($offset): bool { return $offset === 'config'; }
    public function offsetGet($offset): mixed { return $offset === 'config' ? $this->config : ($offset === 'cache' ? $this->cache : null); }
    public function offsetSet($offset, $value): void {}
    public function offsetUnset($offset): void {}
    public function singleton(string $key, callable $factory): void { $this->bindings[$key] = $factory; }
    public function bind(string $key, callable $factory): void { $this->bindings[$key] = $factory; }
    public function alias(string $key, string $alias): void {}
    public function bound(string $key): bool { return isset($this->bindings[$key]); }
    public function make(string $key)
    {
        if (!isset($this->bindings[$key])) { throw new \RuntimeException("No binding for {$key}"); }
        return ($this->bindings[$key])($this);
    }
}

class FakeLaravelCacheRepository
{
    private $values = [];
    public function has(string $key): bool { return array_key_exists($key, $this->values); }
    public function add(string $key, $value, int $ttl = 0): bool
    {
        if ($this->has($key)) { return false; }
        $this->values[$key] = $value;
        return true;
    }
    public function put(string $key, $value, int $ttl = 0): void { $this->values[$key] = $value; }
    public function forget(string $key): bool { unset($this->values[$key]); return true; }
}

class FakeLaravelCacheManager
{
    public $repository;
    public function __construct() { $this->repository = new FakeLaravelCacheRepository(); }
    public function store() { return $this->repository; }
}

class TamaraLaravelProviderTest
{
    public function run(): void
    {
        $app = new FakeLaravelApp([
            'merchant_token' => 'merchant-token',
            'notification_token' => 'notification-token',
            'sandbox' => true,
            'auto_authorise' => true,
            'requires_shipping_address' => false,
        ]);
        (new TamaraServiceProvider($app))->register();

        $service = $app->make(TamaraService::class);
        testExpect($service->getClient()->getApiUrl() === TamaraClient::API_URL_SANDBOX);
        $serviceReflection = new ReflectionProperty(TamaraService::class, 'builder');
        $builder = $serviceReflection->getValue($service);
        $builderReflection = new ReflectionProperty(TamaraOrderBuilder::class, 'requiresShippingAddress');
        testExpect($builderReflection->getValue($builder) === false);

        $processor = $app->make(TamaraWebhookProcessor::class);
        $processorReflection = new ReflectionProperty(TamaraWebhookProcessor::class, 'autoAuthorise');
        testExpect($processorReflection->getValue($processor) === true);
        $dedupReflection = new ReflectionProperty(TamaraWebhookProcessor::class, 'deduplicator');
        $deduplicator = $dedupReflection->getValue($processor);
        testExpect($deduplicator instanceof TamaraAtomicCacheDeduplicator);
        testExpect($deduplicator->claim('same-event') === true);
        testExpect($deduplicator->claim('same-event') === false);
    }
}
