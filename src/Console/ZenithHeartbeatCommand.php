<?php

namespace Gravito\Zenith\Laravel\Console;

use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Gravito\Zenith\Laravel\DataTransferObjects\HeartbeatEntry;
use Gravito\Zenith\Laravel\Support\ChannelRegistry;
use Gravito\Zenith\Laravel\Support\GeneratesWorkerId;
use Illuminate\Console\Command;

class ZenithHeartbeatCommand extends Command
{
    use GeneratesWorkerId;

    protected $signature = 'zenith:heartbeat';
    protected $description = 'Send periodic heartbeat to Zenith (run as daemon)';

    protected string $workerId;
    protected int $startTime;

    public function handle(TransportInterface $transport, ChannelRegistry $channels): int
    {
        $this->workerId = $this->generateWorkerId();
        $this->startTime = time();

        $interval = config('zenith.heartbeat.interval', 5);
        $ttl = config('zenith.heartbeat.ttl', 30);

        $this->info("Starting Zenith Heartbeat (Worker ID: {$this->workerId})");
        $this->info("Interval: {$interval}s, TTL: {$ttl}s");
        $this->newLine();

        while (true) { // @phpstan-ignore while.alwaysTrue
            $this->sendHeartbeat($transport, $channels, $ttl);
            sleep($interval);
        }
    }

    protected function sendHeartbeat(TransportInterface $transport, ChannelRegistry $channels, int $ttl): void
    {
        $entry = new HeartbeatEntry(
            id: $this->workerId,
            hostname: gethostname(),
            pid: getmypid(),
            uptime: time() - $this->startTime,
            queues: $this->getMonitoredQueues(),
            concurrency: $this->getConcurrency(),
            memoryUsedMb: round(memory_get_usage() / 1024 / 1024, 2),
            memoryPeakMb: round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            timestamp: now()->toIso8601String(),
            loadAvg: $this->getLoadAverage(),
        );

        $transport->store(
            $channels->workerKey($this->workerId),
            $entry->toArray(),
            $ttl,
        );

        $this->line("heartbeat sent at " . now()->format('H:i:s'));
    }

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

    protected function getConcurrency(): int
    {
        if (config('horizon')) {
            $environments = config('horizon.environments', []);
            $environment = config('app.env', 'production');

            if (isset($environments[$environment]) && is_array($environments[$environment])) {
                $supervisors = $environments[$environment];
                $supervisor = reset($supervisors);

                if (is_array($supervisor) && isset($supervisor['processes'])) {
                    $value = (int) $supervisor['processes'];
                    return $value > 0 ? $value : 1;
                }
            }
        }

        return 1;
    }

    protected function getLoadAverage(): array
    {
        if (function_exists('sys_getloadavg')) {
            return sys_getloadavg();
        }

        return [0, 0, 0];
    }
}
