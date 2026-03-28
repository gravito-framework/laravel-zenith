<?php

namespace Gravito\Zenith\Laravel\Tests\Unit;

use Gravito\Zenith\Laravel\Support\ChannelRegistry;
use PHPUnit\Framework\TestCase;

class ChannelRegistryTest extends TestCase
{
    /** @test */
    public function logs_returns_configured_channel_name(): void
    {
        $registry = new ChannelRegistry(['logs' => 'myapp:logs']);

        $this->assertSame('myapp:logs', $registry->logs());
    }

    /** @test */
    public function logs_returns_default_when_not_configured(): void
    {
        $registry = new ChannelRegistry([]);

        $this->assertSame('zenith:logs', $registry->logs());
    }

    /** @test */
    public function worker_key_appends_worker_id(): void
    {
        $registry = new ChannelRegistry(['worker' => 'myapp:worker:']);

        $this->assertSame('myapp:worker:web-1-123', $registry->workerKey('web-1-123'));
    }

    /** @test */
    public function worker_key_uses_default_prefix(): void
    {
        $registry = new ChannelRegistry([]);

        $this->assertSame('zenith:worker:abc', $registry->workerKey('abc'));
    }

    /** @test */
    public function throughput_key_appends_window(): void
    {
        $registry = new ChannelRegistry(['throughput' => 'custom:throughput:']);

        $this->assertSame('custom:throughput:28800', $registry->throughputKey(28800));
    }

    /** @test */
    public function throughput_key_uses_default_prefix(): void
    {
        $registry = new ChannelRegistry([]);

        $this->assertSame('zenith:throughput:999', $registry->throughputKey(999));
    }

    /** @test */
    public function http_metric_key_appends_category_and_window(): void
    {
        $registry = new ChannelRegistry(['http' => 'app:http:']);

        $this->assertSame('app:http:2xx:28800', $registry->httpMetricKey('2xx', 28800));
    }

    /** @test */
    public function http_metric_key_uses_default_prefix(): void
    {
        $registry = new ChannelRegistry([]);

        $this->assertSame('zenith:metrics:http:5xx:100', $registry->httpMetricKey('5xx', 100));
    }

    /** @test */
    public function counter_ttl_returns_configured_value(): void
    {
        $registry = new ChannelRegistry(['counter_ttl' => 7200]);

        $this->assertSame(7200, $registry->counterTtl());
    }

    /** @test */
    public function counter_ttl_returns_default_3600(): void
    {
        $registry = new ChannelRegistry([]);

        $this->assertSame(3600, $registry->counterTtl());
    }
}
