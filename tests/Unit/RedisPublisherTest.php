<?php

namespace Gravito\Zenith\Laravel\Tests\Unit;

use Gravito\Zenith\Laravel\Support\RedisPublisher;
use Gravito\Zenith\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Redis;

class RedisPublisherTest extends TestCase
{
    protected RedisPublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publisher = new RedisPublisher();
    }

    /** @test */
    public function it_can_publish_messages_to_redis(): void
    {
        Redis::shouldReceive('connection')
            ->once()
            ->with('default')
            ->andReturnSelf();

        Redis::shouldReceive('publish')
            ->once()
            ->with('test_channel', \Mockery::type('string'))
            ->andReturn(1);

        $this->publisher->publish('test_channel', ['message' => 'test']);
    }

    /** @test */
    public function it_does_not_publish_when_zenith_is_disabled(): void
    {
        config(['zenith.enabled' => false]);

        Redis::shouldReceive('connection')->never();
        Redis::shouldReceive('publish')->never();

        $this->publisher->publish('test_channel', ['message' => 'test']);
    }

    /** @test */
    public function it_can_set_keys_with_ttl(): void
    {
        Redis::shouldReceive('connection')
            ->once()
            ->with('default')
            ->andReturnSelf();

        Redis::shouldReceive('setex')
            ->once()
            ->with('test_key', 60, \Mockery::type('string'))
            ->andReturn('OK');

        $this->publisher->setex('test_key', ['data' => 'value'], 60);
    }

    /** @test */
    public function it_can_increment_counters(): void
    {
        Redis::shouldReceive('connection')
            ->twice()
            ->with('default')
            ->andReturnSelf();

        Redis::shouldReceive('incr')
            ->once()
            ->with('test_counter')
            ->andReturn(1);

        Redis::shouldReceive('expire')
            ->once()
            ->with('test_counter', 3600)
            ->andReturn(1);

        $this->publisher->incr('test_counter', 3600);
    }

    /** @test */
    public function it_silently_fails_on_redis_errors(): void
    {
        Redis::shouldReceive('connection')
            ->once()
            ->andThrow(new \Exception('Redis connection failed'));

        // Should not throw exception
        $this->publisher->publish('test_channel', ['message' => 'test']);
        
        $this->assertTrue(true); // If we get here, silent failure worked
    }
}
