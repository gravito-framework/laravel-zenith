<?php

namespace Gravito\Zenith\Laravel\Tests\Unit;

use Gravito\Zenith\Laravel\DataTransferObjects\LogEntry;
use PHPUnit\Framework\TestCase;

class LogEntryTest extends TestCase
{
    /** @test */
    public function to_array_returns_all_fields(): void
    {
        $entry = new LogEntry(
            level: 'error',
            message: 'Something failed',
            workerId: 'host-123',
            timestamp: '2026-03-28T12:00:00+00:00',
            context: ['key' => 'value'],
        );

        $result = $entry->toArray();

        $this->assertSame([
            'level' => 'error',
            'message' => 'Something failed',
            'workerId' => 'host-123',
            'timestamp' => '2026-03-28T12:00:00+00:00',
            'context' => ['key' => 'value'],
        ], $result);
    }

    /** @test */
    public function to_array_defaults_context_to_empty_array(): void
    {
        $entry = new LogEntry(
            level: 'info',
            message: 'Test',
            workerId: 'host-1',
            timestamp: '2026-03-28T12:00:00+00:00',
        );

        $this->assertSame([], $entry->toArray()['context']);
    }

    /** @test */
    public function properties_are_readonly(): void
    {
        $entry = new LogEntry(
            level: 'info',
            message: 'Test',
            workerId: 'host-1',
            timestamp: '2026-03-28T12:00:00+00:00',
        );

        $this->assertSame('info', $entry->level);
        $this->assertSame('Test', $entry->message);
        $this->assertSame('host-1', $entry->workerId);
    }
}
