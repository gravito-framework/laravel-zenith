<?php

namespace Gravito\Zenith\Laravel\Console;

use Gravito\Zenith\Laravel\Support\RedisPublisher;
use Illuminate\Console\Command;

class ZenithHeartbeatCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zenith:heartbeat';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send periodic heartbeat to Gravito Zenith (run as daemon)';

    protected RedisPublisher $publisher;
    protected string $workerId;
    protected int $startTime;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->publisher = new RedisPublisher();
        $this->workerId = $this->generateWorkerId();
        $this->startTime = time();

        $interval = config('zenith.heartbeat.interval', 5);
        $ttl = config('zenith.heartbeat.ttl', 30);

        $this->info("Starting Zenith Heartbeat (Worker ID: {$this->workerId})");
        $this->info("Interval: {$interval}s, TTL: {$ttl}s");
        $this->newLine();

        while (true) {
            $this->sendHeartbeat($ttl);
            sleep($interval);
        }

        return self::SUCCESS;
    }

    /**
     * Send a heartbeat to Redis.
     */
    protected function sendHeartbeat(int $ttl): void
    {
        $payload = [
            'id' => $this->workerId,
            'hostname' => gethostname(),
            'pid' => getmypid(),
            'uptime' => time() - $this->startTime,
            'queues' => $this->getMonitoredQueues(),
            'concurrency' => $this->getConcurrency(),
            'memory' => [
                'rss' => $this->formatMemory(memory_get_usage(true)),
                'heapUsed' => $this->formatMemory(memory_get_usage()),
                'heapTotal' => 'N/A',
            ],
            'timestamp' => now()->toIso8601String(),
            'loadAvg' => $this->getLoadAverage(),
        ];

        $key = "flux_console:worker:{$this->workerId}";
        $this->publisher->setex($key, $payload, $ttl);

        $this->line("❤️  Heartbeat sent at " . now()->format('H:i:s'));
    }

    /**
     * Get the list of monitored queues.
     */
    protected function getMonitoredQueues(): array
    {
        $queueConnection = config('queue.default');
        $queues = config("queue.connections.{$queueConnection}.queue");

        if (is_string($queues)) {
            return [$queues];
        }

        if (is_array($queues)) {
            return $queues;
        }

        return ['default'];
    }

    /**
     * Get worker concurrency (from Horizon config if available).
     */
    protected function getConcurrency(): int
    {
        // Try to get from Horizon config
        if (config('horizon')) {
            $environments = config('horizon.environments', []);
            $environment = config('app.env', 'production');
            
            if (isset($environments[$environment])) {
                $supervisors = $environments[$environment];
                $supervisor = reset($supervisors);
                
                if (isset($supervisor['processes'])) {
                    return (int) $supervisor['processes'];
                }
            }
        }

        // Fallback to queue worker config
        return 1;
    }

    /**
     * Get system load average.
     */
    protected function getLoadAverage(): array
    {
        if (function_exists('sys_getloadavg')) {
            return sys_getloadavg();
        }

        return [0, 0, 0];
    }

    /**
     * Format memory in human-readable format.
     */
    protected function formatMemory(int $bytes): string
    {
        $mb = round($bytes / 1024 / 1024, 2);
        return "{$mb} MB";
    }

    /**
     * Generate a unique worker ID.
     */
    protected function generateWorkerId(): string
    {
        return gethostname() . '-' . getmypid();
    }
}
