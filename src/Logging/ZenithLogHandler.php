<?php

namespace Gravito\Zenith\Laravel\Logging;

use Gravito\Zenith\Laravel\Support\RedisPublisher;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Monolog\Level;

/**
 * Monolog handler that publishes logs to Zenith via Redis.
 */
class ZenithLogHandler extends AbstractProcessingHandler
{
    protected RedisPublisher $publisher;
    protected string $workerId;

    public function __construct($level = Level::Debug, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        
        $this->publisher = new RedisPublisher();
        $this->workerId = $this->generateWorkerId();
    }

    /**
     * Write the log record to Redis.
     */
    protected function write(LogRecord $record): void
    {
        if (!config('zenith.logging.enabled', true)) {
            return;
        }

        // Map Monolog level to Zenith level
        $level = $this->mapLevel($record->level);

        $payload = [
            'level' => $level,
            'message' => $record->message,
            'workerId' => $this->workerId,
            'timestamp' => $record->datetime->format('c'), // ISO 8601
            'context' => $record->context,
        ];

        // Add queue info if present in context
        if (isset($record->context['queue'])) {
            $payload['queue'] = $record->context['queue'];
        }

        $this->publisher->publish('flux_console:logs', $payload);
    }

    /**
     * Map Monolog level to Zenith level.
     */
    protected function mapLevel(Level $level): string
    {
        return match ($level->value) {
            Level::Error->value, Level::Critical->value, Level::Alert->value, Level::Emergency->value => 'error',
            Level::Warning->value => 'warn',
            default => 'info',
        };
    }

    /**
     * Generate a unique worker ID.
     */
    protected function generateWorkerId(): string
    {
        return gethostname() . '-' . getmypid();
    }
}
