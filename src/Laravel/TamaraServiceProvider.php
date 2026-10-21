<?php

namespace Tamara\Laravel;

use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Tamara\TamaraClient;
use Tamara\TamaraService;

class TamaraServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/tamara.php' => $this->app->configPath('tamara.php'),
            ], 'tamara-config');
        }
    }

    /**
     * Register package services in Laravel IoC container.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/tamara.php', 'tamara');

        // Bind TamaraClient as singleton
        $this->app->singleton(TamaraClient::class, function ($app) {
            $config = $app['config']->get('tamara', []);
            $logger = $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null;

            return new TamaraClient(
                (string) ($config['merchant_token'] ?? env('TAMARA_MERCHANT_TOKEN', '')),
                (string) ($config['notification_token'] ?? env('TAMARA_NOTIFICATION_TOKEN', '')),
                (bool) ($config['sandbox'] ?? env('TAMARA_SANDBOX', false)),
                null,
                $logger
            );
        });

        // Bind TamaraService as singleton
        $this->app->singleton(TamaraService::class, function ($app) {
            return new TamaraService($app->make(TamaraClient::class));
        });

        // Alias for Facade access
        $this->app->alias(TamaraService::class, 'tamara');
    }

    public function provides(): array
    {
        return [TamaraClient::class, TamaraService::class, 'tamara'];
    }
}

