<?php

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function testExpect(bool $condition, string $message = 'Expectation failed'): void
{
    if (!$condition) {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0] ?? [];
        throw new RuntimeException($message . ' at ' . ($caller['file'] ?? 'unknown') . ':' . ($caller['line'] ?? 0));
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

