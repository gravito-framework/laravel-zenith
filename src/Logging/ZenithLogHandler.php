<?php

namespace Gravito\Zenith\Laravel\Logging;

use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Gravito\Zenith\Laravel\DataTransferObjects\LogEntry;
use Gravito\Zenith\Laravel\Support\ChannelRegistry;
use Gravito\Zenith\Laravel\Support\GeneratesWorkerId;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Monolog\Level;
use Throwable;

class ZenithLogHandler extends AbstractProcessingHandler
{
    use GeneratesWorkerId;

    protected string $workerId;

    public function __construct(
        protected TransportInterface $transport,
        protected ChannelRegistry $channels,
        $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
        $this->workerId = $this->generateWorkerId();
    }

    protected function write(LogRecord $record): void
    {
        try {
            if (!config('zenith.logging.enabled', true)) {
                return;
            }

            $entry = new LogEntry(
                level: $this->mapLevel($record->level),
                message: $record->message,
                workerId: $this->workerId,
                timestamp: $record->datetime->format('c'),
                context: $record->context,
            );

            $this->transport->publish($this->channels->logs(), $entry->toArray());
        } catch (Throwable $e) {
            // Silently fail to avoid breaking the logging pipeline
        }
    }

    protected function mapLevel(Level $level): string
    {
        return match ($level->value) {
            Level::Error->value, Level::Critical->value, Level::Alert->value, Level::Emergency->value => 'error',
            Level::Warning->value => 'warn',
            default => 'info',
        };
    }
}
