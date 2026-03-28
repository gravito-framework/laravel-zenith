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
        $this->transport = new RedisTransport('default');
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
                return $args[0] === 'test-topic' && is_string($args[1]);
            }))
            ->andReturn(1);

        Redis::shouldReceive('connection')
            ->once()
            ->with('default')
            ->andReturn($connection);

        $this->transport->publish('test-topic', ['message' => 'test']);
    }

    /** @test */
    public function it_can_store_array_data_with_ttl(): void
    {
        $connection = \Mockery::mock(\Illuminate\Redis\Connections\Connection::class);
        $connection->shouldReceive('command')
            ->once()
            ->with('setex', \Mockery::on(function ($args) {
                return $args[0] === 'test-key'
                    && $args[1] === 60
                    && json_decode($args[2], true) === ['data' => 'value'];
            }))
            ->andReturn('OK');

        Redis::shouldReceive('connection')
            ->once()
            ->with('default')
            ->andReturn($connection);

        $this->transport->store('test-key', ['data' => 'value'], 60);
    }

    /** @test */
    public function it_can_increment_counters(): void
    {
        $connection = \Mockery::mock(\Illuminate\Redis\Connections\Connection::class);
        $connection->shouldReceive('command')
            ->once()
            ->with('incr', ['test-counter'])
            ->andReturn(1);

        $connection->shouldReceive('command')
            ->once()
            ->with('expire', ['test-counter', 3600])
            ->andReturn(1);

        Redis::shouldReceive('connection')
            ->with('default')
            ->andReturn($connection);

        $this->transport->increment('test-counter', 3600);
    }

    /** @test */
    public function it_silently_fails_on_redis_errors(): void
    {
        Redis::shouldReceive('connection')
            ->once()
            ->andThrow(new \Exception('Redis connection failed'));

        $this->transport->publish('test-topic', ['message' => 'test']);

        $this->assertTrue(true);
    }
}
