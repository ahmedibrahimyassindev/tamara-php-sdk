<?php

function testExpect(bool $condition, string $message = 'Expectation failed'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

if (!interface_exists('Psr\Log\LoggerInterface')) {
    require_once __DIR__ . '/psr_log_stub.php';
}

if (!class_exists(\Illuminate\Support\ServiceProvider::class)) {
    require_once __DIR__ . '/laravel_stubs.php';
}

spl_autoload_register(function ($class) {
    if (str_starts_with($class, 'Tamara\\Tests\\')) {
        $rel = substr($class, strlen('Tamara\\Tests\\'));
        $path = __DIR__ . '/' . str_replace('\\', '/', $rel) . '.php';
        if (file_exists($path)) {
            require_once $path;
        }
    } elseif (str_starts_with($class, 'Tamara\\')) {
        $rel = substr($class, strlen('Tamara\\'));
        $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $rel) . '.php';
        if (file_exists($path)) {
            require_once $path;
        }
    }
});

