<?php

namespace Gravito\Zenith\Laravel\Tests\Unit;

use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Gravito\Zenith\Laravel\Transport\NullTransport;
use Gravito\Zenith\Laravel\Transport\RedisTransport;
use Gravito\Zenith\Laravel\Transport\TransportManager;
use Gravito\Zenith\Laravel\Tests\TestCase;

class TransportManagerTest extends TestCase
{
    /** @test */
    public function it_resolves_redis_driver_by_default(): void
    {
        config([
            'zenith.enabled' => true,
            'zenith.transport' => ['driver' => 'redis', 'connection' => 'default'],
        ]);

        $manager = $this->app->make(TransportManager::class);

        $this->assertInstanceOf(RedisTransport::class, $manager->driver());
    }

    /** @test */
    public function it_resolves_null_driver(): void
    {
        config([
            'zenith.enabled' => true,
            'zenith.transport' => ['driver' => 'null'],
        ]);

        $manager = $this->app->make(TransportManager::class);

        $this->assertInstanceOf(NullTransport::class, $manager->driver());
    }

    /** @test */
    public function it_falls_back_to_null_when_disabled(): void
    {
        config([
            'zenith.enabled' => false,
            'zenith.transport' => ['driver' => 'redis', 'connection' => 'default'],
        ]);

        $manager = $this->app->make(TransportManager::class);

        $this->assertInstanceOf(NullTransport::class, $manager->driver());
    }

    /** @test */
    public function it_supports_custom_drivers_via_extend(): void
    {
        config([
            'zenith.enabled' => true,
            'zenith.transport' => ['driver' => 'custom'],
        ]);

        $manager = $this->app->make(TransportManager::class);

        $customTransport = new NullTransport();
        $manager->extend('custom', fn () => $customTransport);

        $this->assertSame($customTransport, $manager->driver());
    }

    /** @test */
    public function driver_returns_transport_interface(): void
    {
        config([
            'zenith.enabled' => true,
            'zenith.transport' => ['driver' => 'redis', 'connection' => 'default'],
        ]);

        $manager = $this->app->make(TransportManager::class);

        $this->assertInstanceOf(TransportInterface::class, $manager->driver());
    }

    /** @test */
    public function it_defaults_to_redis_when_no_transport_config(): void
    {
        config([
            'zenith.enabled' => true,
            'zenith.transport' => null,
        ]);

        $manager = $this->app->make(TransportManager::class);

        $this->assertInstanceOf(RedisTransport::class, $manager->driver());
    }
}
