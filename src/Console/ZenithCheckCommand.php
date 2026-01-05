<?php

namespace Gravito\Zenith\Laravel\Console;

use Gravito\Zenith\Laravel\Support\RedisPublisher;
use Illuminate\Console\Command;

class ZenithCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zenith:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify Zenith configuration and Redis connection';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔍 Zenith Configuration Check');
        $this->newLine();

        // Check if Zenith is enabled
        $enabled = config('zenith.enabled', false);
        $this->checkItem('Zenith Enabled', $enabled);

        if (!$enabled) {
            $this->warn('Zenith is disabled. Set ZENITH_ENABLED=true in your .env file.');
            return self::FAILURE;
        }

        // Check Redis connection
        $connection = config('zenith.connection', 'default');
        $this->info("Redis Connection: <comment>{$connection}</comment>");

        $publisher = new RedisPublisher($connection);
        $pingSuccess = $publisher->ping();
        $this->checkItem('Redis Connection', $pingSuccess);

        if (!$pingSuccess) {
            $this->error('Failed to connect to Redis. Check your configuration.');
            return self::FAILURE;
        }

        // Test publishing
        $this->info('Testing publish capability...');
        try {
            $publisher->publish('flux_console:logs', [
                'level' => 'info',
                'message' => 'Zenith health check',
                'workerId' => gethostname() . '-check',
                'timestamp' => now()->toIso8601String(),
            ]);
            $this->checkItem('Publish Test', true);
        } catch (\Throwable $e) {
            $this->checkItem('Publish Test', false);
            $this->error("Publish failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        // Display configuration
        $this->newLine();
        $this->info('📋 Configuration:');
        $this->table(
            ['Feature', 'Status'],
            [
                ['Logging', config('zenith.logging.enabled') ? '✓ Enabled' : '✗ Disabled'],
                ['Queue Monitoring', config('zenith.queues.enabled') ? '✓ Enabled' : '✗ Disabled'],
                ['HTTP Monitoring', config('zenith.http.enabled') ? '✓ Enabled' : '✗ Disabled'],
            ]
        );

        $this->newLine();
        $this->info('✅ All checks passed! Zenith is ready.');
        
        return self::SUCCESS;
    }

    /**
     * Display a check item result.
     */
    protected function checkItem(string $label, bool $success): void
    {
        $icon = $success ? '✓' : '✗';
        $color = $success ? 'info' : 'error';
        $this->line("  {$icon} {$label}", $color);
    }
}
