<?php

namespace Gravito\Zenith\Laravel\Tests\Feature;

use Gravito\Zenith\Laravel\Console\ZenithCheckCommand;
use Gravito\Zenith\Laravel\Console\ZenithHeartbeatCommand;
use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Gravito\Zenith\Laravel\Queue\ZenithQueueSubscriber;
use Gravito\Zenith\Laravel\Support\ChannelRegistry;
use Gravito\Zenith\Laravel\Tests\TestCase;
use Gravito\Zenith\Laravel\Transport\RedisTransport;
use Illuminate\Log\LogManager;
use InvalidArgumentException;

class ZenithServiceProviderTest extends TestCase
{
    /**
     * @test
     */
    public function config_is_merged_correctly(): void
    {
        // Default config values defined in config/zenith.php should be present
        $this->assertTrue(config('zenith.enabled'));
        $this->assertSame('default', config('zenith.connection'));
        $this->assertTrue(config('zenith.logging.enabled'));
        $this->assertTrue(config('zenith.queues.enabled'));
        $this->assertTrue(config('zenith.http.enabled'));
        $this->assertIsArray(config('zenith.http.ignore_paths'));
        $this->assertIsInt(config('zenith.heartbeat.interval'));
        $this->assertIsInt(config('zenith.heartbeat.ttl'));
    }

    /**
     * @test
     */
    public function commands_are_registered(): void
    {
        $commands = $this->app->make('Illuminate\Contracts\Console\Kernel')->all();

        $this->assertArrayHasKey('zenith:check', $commands);
        $this->assertArrayHasKey('zenith:heartbeat', $commands);
        $this->assertInstanceOf(ZenithCheckCommand::class, $commands['zenith:check']);
        $this->assertInstanceOf(ZenithHeartbeatCommand::class, $commands['zenith:heartbeat']);
    }

    /**
     * @test
     */
    public function zenith_log_driver_is_registered(): void
    {
        // Ensure a logging channel config entry exists so the driver factory is invoked.
        config(['logging.channels.zenith' => ['driver' => 'zenith', 'level' => 'debug']]);

        /** @var LogManager $logManager */
        $logManager = $this->app->make('log');

        // Resolving the channel exercises the factory registered by the service provider.
        $channel = $logManager->channel('zenith');

        // The factory returns a Monolog\Logger wrapped by Laravel's log channel.
        $monologLogger = $channel->getLogger();
        $this->assertInstanceOf(\Monolog\Logger::class, $monologLogger);

        // The Monolog logger must have exactly one ZenithLogHandler attached.
        $handlers = $monologLogger->getHandlers();
        $this->assertNotEmpty($handlers, 'Expected at least one handler on the zenith logger.');
        $this->assertInstanceOf(
            \Gravito\Zenith\Laravel\Logging\ZenithLogHandler::class,
            $handlers[0]
        );
    }

    /**
     * @test
     */
    public function queue_subscriber_is_registered_when_queues_enabled(): void
    {
        // With queues.enabled = true (set by TestCase::defineEnvironment), the
        // subscriber should have been subscribed to the event dispatcher.
        $dispatcher = $this->app['events'];

        // ZenithQueueSubscriber::subscribe registers listeners for these events.
        $this->assertTrue(
            $dispatcher->hasListeners(\Illuminate\Queue\Events\JobProcessing::class),
            'Expected JobProcessing listeners to be registered by ZenithQueueSubscriber.'
        );
        $this->assertTrue(
            $dispatcher->hasListeners(\Illuminate\Queue\Events\JobProcessed::class),
            'Expected JobProcessed listeners to be registered by ZenithQueueSubscriber.'
        );
        $this->assertTrue(
            $dispatcher->hasListeners(\Illuminate\Queue\Events\JobFailed::class),
            'Expected JobFailed listeners to be registered by ZenithQueueSubscriber.'
        );
    }

    /**
     * @test
     */
    public function no_subscriber_registered_when_queues_disabled(): void
    {
        // Spin up a fresh event dispatcher so it carries no pre-existing listeners.
        $freshDispatcher = new \Illuminate\Events\Dispatcher($this->app);

        // Temporarily replace the app's event dispatcher.
        $this->app->instance('events', $freshDispatcher);

        // Override config to disable queue monitoring.
        config([
            'zenith.enabled'        => true,
            'zenith.queues.enabled' => false,
        ]);

        // Invoke only the subscriber registration method via a fresh provider instance.
        $provider = new \Gravito\Zenith\Laravel\ZenithServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $this->assertFalse(
            $freshDispatcher->hasListeners(\Illuminate\Queue\Events\JobProcessing::class),
            'JobProcessing listeners should not be registered when queues.enabled = false.'
        );
        $this->assertFalse(
            $freshDispatcher->hasListeners(\Illuminate\Queue\Events\JobProcessed::class),
            'JobProcessed listeners should not be registered when queues.enabled = false.'
        );
        $this->assertFalse(
            $freshDispatcher->hasListeners(\Illuminate\Queue\Events\JobFailed::class),
            'JobFailed listeners should not be registered when queues.enabled = false.'
        );
    }

    /**
     * @test
     */
    public function no_log_driver_registered_when_logging_disabled(): void
    {
        // Replace the app's log binding with a fresh LogManager that has no
        // custom drivers, then boot the provider with logging disabled so the
        // facade-backed Log::extend() call is skipped.
        $freshLogManager = new \Illuminate\Log\LogManager($this->app);
        $this->app->instance('log', $freshLogManager);
        \Illuminate\Support\Facades\Log::clearResolvedInstances();

        config([
            'zenith.enabled'         => true,
            'zenith.logging.enabled' => false,
            'zenith.queues.enabled'  => false,
        ]);

        $provider = new \Gravito\Zenith\Laravel\ZenithServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        // When logging is disabled the service provider must NOT call Log::extend('zenith').
        // Inspect the customCreators property on the fresh log manager via reflection.
        $reflection = new \ReflectionProperty(\Illuminate\Log\LogManager::class, 'customCreators');
        $reflection->setAccessible(true);
        $customCreators = $reflection->getValue($freshLogManager);

        $this->assertArrayNotHasKey(
            'zenith',
            $customCreators,
            'Log::extend("zenith") should not be called when logging.enabled = false.'
        );
    }

    /** @test */
    public function transport_interface_is_bound_as_singleton(): void
    {
        $instance1 = $this->app->make(TransportInterface::class);
        $instance2 = $this->app->make(TransportInterface::class);

        $this->assertInstanceOf(RedisTransport::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    /** @test */
    public function channel_registry_is_bound_as_singleton(): void
    {
        $instance1 = $this->app->make(ChannelRegistry::class);
        $instance2 = $this->app->make(ChannelRegistry::class);

        $this->assertInstanceOf(ChannelRegistry::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    /** @test */
    public function channel_registry_uses_config_values(): void
    {
        config(['zenith.channels.logs' => 'custom:logs']);

        // Re-register to pick up new config
        $provider = new \Gravito\Zenith\Laravel\ZenithServiceProvider($this->app);
        $provider->register();

        $registry = $this->app->make(ChannelRegistry::class);
        $this->assertSame('custom:logs', $registry->logs());
    }

    /**
     * @test
     */
    public function config_validation_runs_when_enabled(): void
    {
        // The service provider calls ConfigValidator::validate() when enabled.
        // Supply an invalid config to confirm validation is actually executed.
        $app = $this->createApplication();
        $app['config']->set('zenith', [
            'enabled'   => true,
            'http'      => [
                'slow_threshold' => -5,   // invalid — triggers InvalidArgumentException
                'ignore_paths'   => [],
            ],
            'heartbeat' => [
                'interval' => 5,
                'ttl'      => 30,
            ],
            'queues'    => [
                'ignore_jobs' => [],
            ],
            'logging'   => ['enabled' => false],
        ]);

        $provider = new \Gravito\Zenith\Laravel\ZenithServiceProvider($app);
        $provider->register();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('zenith.http.slow_threshold must be a non-negative number.');

        $provider->boot();
    }
}
