<?php

namespace Gravito\Zenith\Laravel\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Gravito\Zenith\Laravel\ZenithServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * Get package providers.
     */
    protected function getPackageProviders($app): array
    {
        return [
            ZenithServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     */
    protected function defineEnvironment($app): void
    {
        // Setup default configuration
        $app['config']->set('zenith.enabled', true);
        $app['config']->set('zenith.transport', [
            'driver' => 'redis',
            'connection' => 'default',
        ]);
        $app['config']->set('zenith.logging.enabled', true);
        $app['config']->set('zenith.queues.enabled', true);
        $app['config']->set('zenith.http.enabled', true);

        // Setup Redis connection for testing
        $app['config']->set('database.redis.default', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null),
            'port' => env('REDIS_PORT', 6379),
            'database' => env('REDIS_DB', 0),
        ]);
    }
}
