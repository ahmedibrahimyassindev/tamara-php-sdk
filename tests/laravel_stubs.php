<?php

namespace {
    if (!function_exists('env')) {
        function env(string $key, $default = null)
        {
            return $default;
        }
    }
}

namespace Illuminate\Support {
    class ServiceProvider
    {
        protected $app;

        public function __construct($app)
        {
            $this->app = $app;
        }

        protected function mergeConfigFrom(string $path, string $key): void
        {
            $defaults = require $path;
            $current = $this->app['config']->get($key, []);
            $this->app['config']->set($key, array_merge($defaults, $current));
        }
    }
}
