<?php

namespace Gravito\Zenith\Laravel;

use Gravito\Zenith\Laravel\Console\ZenithCheckCommand;
use Gravito\Zenith\Laravel\Console\ZenithHeartbeatCommand;
use Gravito\Zenith\Laravel\Logging\ZenithLogger;
use Gravito\Zenith\Laravel\Queue\ZenithQueueSubscriber;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class ZenithServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/zenith.php',
            'zenith'
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__ . '/../config/zenith.php' => config_path('zenith.php'),
        ], 'zenith-config');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                ZenithCheckCommand::class,
                ZenithHeartbeatCommand::class,
            ]);
        }

        // Register custom log driver
        $this->registerLogDriver();

        // Register queue event subscriber
        $this->registerQueueSubscriber();
    }

    /**
     * Register the Zenith log driver.
     */
    protected function registerLogDriver(): void
    {
        if (!config('zenith.enabled', true) || !config('zenith.logging.enabled', true)) {
            return;
        }

        Log::extend('zenith', function ($app, array $config) {
            return (new ZenithLogger())($config);
        });
    }

    /**
     * Register the queue event subscriber.
     */
    protected function registerQueueSubscriber(): void
    {
        if (!config('zenith.enabled', true) || !config('zenith.queues.enabled', true)) {
            return;
        }

        $this->app['events']->subscribe(ZenithQueueSubscriber::class);
    }
}
