<?php

namespace Gravito\Zenith\Laravel\Tests\Unit;

use Gravito\Zenith\Laravel\DataTransferObjects\HeartbeatEntry;
use PHPUnit\Framework\TestCase;

class HeartbeatEntryTest extends TestCase
{
    /** @test */
    public function to_array_returns_all_fields_with_language_neutral_names(): void
    {
        $entry = new HeartbeatEntry(
            id: 'web-1-12345',
            hostname: 'web-1',
            pid: 12345,
            uptime: 3600,
            queues: ['default', 'high'],
            concurrency: 4,
            memoryUsedMb: 64.5,
            memoryPeakMb: 128.0,
            timestamp: '2026-03-28T12:00:00+00:00',
            loadAvg: [1.5, 1.2, 0.9],
        );

        $result = $entry->toArray();

        $this->assertSame('web-1-12345', $result['id']);
        $this->assertSame('web-1', $result['hostname']);
        $this->assertSame(12345, $result['pid']);
        $this->assertSame(3600, $result['uptime']);
        $this->assertSame(['default', 'high'], $result['queues']);
        $this->assertSame(4, $result['concurrency']);
        $this->assertSame(64.5, $result['memoryUsedMb']);
        $this->assertSame(128.0, $result['memoryPeakMb']);
        $this->assertSame('2026-03-28T12:00:00+00:00', $result['timestamp']);
        $this->assertSame([1.5, 1.2, 0.9], $result['loadAvg']);
    }

    /** @test */
    public function to_array_does_not_contain_legacy_heap_or_rss_fields(): void
    {
        $entry = new HeartbeatEntry(
            id: 'w-1',
            hostname: 'h',
            pid: 1,
            uptime: 0,
            queues: [],
            concurrency: 1,
            memoryUsedMb: 0.0,
            memoryPeakMb: 0.0,
            timestamp: '2026-03-28T12:00:00+00:00',
            loadAvg: [0, 0, 0],
        );

        $result = $entry->toArray();

        $this->assertArrayNotHasKey('memory', $result);
        $this->assertArrayNotHasKey('heapUsed', $result);
        $this->assertArrayNotHasKey('heapTotal', $result);
        $this->assertArrayNotHasKey('rss', $result);
    }
}
