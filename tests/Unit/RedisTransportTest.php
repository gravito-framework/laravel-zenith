<?php

namespace Gravito\Zenith\Laravel\Tests\Unit;

use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Gravito\Zenith\Laravel\Transport\RedisTransport;
use Gravito\Zenith\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Redis;

class RedisTransportTest extends TestCase
{
    protected RedisTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new RedisTransport();
    }

    /** @test */
    public function it_implements_transport_interface(): void
    {
        $this->assertInstanceOf(TransportInterface::class, $this->transport);
    }

    /** @test */
    public function it_can_publish_messages_to_redis(): void
    {
        $connection = \Mockery::mock(\Illuminate\Redis\Connections\Connection::class);
        $connection->shouldReceive('command')
            ->once()
            ->with('publish', \Mockery::on(function ($args) {
                return $args[0] === 'test_channel' && is_string($args[1]);
            }))
            ->andReturn(1);

        Redis::shouldReceive('connection')
            ->once()
            ->with('default')
            ->andReturn($connection);

        $this->transport->publish('test_channel', ['message' => 'test']);
    }

    /** @test */
    public function it_does_not_publish_when_zenith_is_disabled(): void
    {
        config(['zenith.enabled' => false]);

        Redis::shouldReceive('connection')->never();

        $this->transport->publish('test_channel', ['message' => 'test']);
    }

    /** @test */
    public function it_can_store_values_with_ttl(): void
    {
        $connection = \Mockery::mock(\Illuminate\Redis\Connections\Connection::class);
        $connection->shouldReceive('command')
            ->once()
            ->with('setex', \Mockery::on(function ($args) {
                return $args[0] === 'test_key' && $args[1] === 60 && is_string($args[2]);
            }))
            ->andReturn('OK');

        Redis::shouldReceive('connection')
            ->once()
            ->with('default')
            ->andReturn($connection);

        $this->transport->store('test_key', ['data' => 'value'], 60);
    }

    /** @test */
    public function it_can_increment_counters(): void
    {
        $connection = \Mockery::mock(\Illuminate\Redis\Connections\Connection::class);
        $connection->shouldReceive('command')
            ->once()
            ->with('incr', ['test_counter'])
            ->andReturn(1);

        $connection->shouldReceive('command')
            ->once()
            ->with('expire', ['test_counter', 3600])
            ->andReturn(1);

        Redis::shouldReceive('connection')
            ->with('default')
            ->andReturn($connection);

        $this->transport->increment('test_counter', 3600);
    }

    /** @test */
    public function it_silently_fails_on_redis_errors(): void
    {
        Redis::shouldReceive('connection')
            ->once()
            ->andThrow(new \Exception('Redis connection failed'));

        $this->transport->publish('test_channel', ['message' => 'test']);

        $this->assertTrue(true);
    }
}
