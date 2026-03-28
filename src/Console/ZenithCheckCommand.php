<?php

namespace Gravito\Zenith\Laravel\Console;

use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Gravito\Zenith\Laravel\DataTransferObjects\LogEntry;
use Gravito\Zenith\Laravel\Support\ChannelRegistry;
use Illuminate\Console\Command;

class ZenithCheckCommand extends Command
{
    protected $signature = 'zenith:check';
    protected $description = 'Verify Zenith configuration and transport connection';

    public function handle(TransportInterface $transport, ChannelRegistry $channels): int
    {
        $this->info('Zenith Configuration Check');
        $this->newLine();

        $enabled = config('zenith.enabled', false);
        $this->checkItem('Zenith Enabled', $enabled);

        if (!$enabled) {
            $this->warn('Zenith is disabled. Set ZENITH_ENABLED=true in your .env file.');
            return self::FAILURE;
        }

        $driver = config('zenith.transport.driver', 'redis');
        $connection = config('zenith.transport.connection', 'default');
        $this->info("Transport: <comment>{$driver}</comment> (connection: {$connection})");

        $pingSuccess = $transport->ping();
        $this->checkItem('Transport Connection', $pingSuccess);

        if (!$pingSuccess) {
            $this->error('Failed to connect to transport. Check your configuration.');
            return self::FAILURE;
        }

        $this->info('Testing publish capability...');
        try {
            $entry = new LogEntry(
                level: 'info',
                message: 'Zenith health check',
                workerId: gethostname() . '-check',
                timestamp: now()->toIso8601String(),
            );
            $transport->publish($channels->logs(), $entry->toArray());
            $this->checkItem('Publish Test', true);
        } catch (\Throwable $e) {
            $this->checkItem('Publish Test', false);
            $this->error("Publish failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Configuration:');
        $this->table(
            ['Feature', 'Status'],
            [
                ['Logging', config('zenith.logging.enabled') ? 'Enabled' : 'Disabled'],
                ['Queue Monitoring', config('zenith.queues.enabled') ? 'Enabled' : 'Disabled'],
                ['HTTP Monitoring', config('zenith.http.enabled') ? 'Enabled' : 'Disabled'],
            ]
        );

        $this->newLine();
        $this->info('All checks passed! Zenith is ready.');

        return self::SUCCESS;
    }

    protected function checkItem(string $label, bool $success): void
    {
        $icon = $success ? 'OK' : 'FAIL';
        $color = $success ? 'info' : 'error';
        $this->line("  [{$icon}] {$label}", $color);
    }
}
