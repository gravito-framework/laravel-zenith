<?php

namespace Gravito\Zenith\Laravel\Tests\Unit;

use Gravito\Zenith\Laravel\DataTransferObjects\MetricEntry;
use PHPUnit\Framework\TestCase;

class MetricEntryTest extends TestCase
{
    /** @test */
    public function to_key_combines_prefix_name_and_window(): void
    {
        $entry = new MetricEntry(
            name: 'http_2xx',
            window: 28800,
            ttl: 3600,
        );

        $result = $entry->toKey('zenith:metrics:http:');

        $this->assertSame('zenith:metrics:http:http_2xx:28800', $result);
    }

    /** @test */
    public function to_key_works_with_custom_prefix(): void
    {
        $entry = new MetricEntry(
            name: 'job_throughput',
            window: 12345,
            ttl: 7200,
        );

        $result = $entry->toKey('myapp:counters:');

        $this->assertSame('myapp:counters:job_throughput:12345', $result);
    }

    /** @test */
    public function properties_are_accessible(): void
    {
        $entry = new MetricEntry(
            name: 'http_slow',
            window: 100,
            ttl: 3600,
        );

        $this->assertSame('http_slow', $entry->name);
        $this->assertSame(100, $entry->window);
        $this->assertSame(3600, $entry->ttl);
    }
}
