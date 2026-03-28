<?php

namespace Gravito\Zenith\Laravel\Support;

use InvalidArgumentException;

/**
 * Validates Zenith configuration values at boot time.
 */
class ConfigValidator
{
    public static function validate(): void
    {
        $config = config('zenith');

        if (!is_array($config)) {
            throw new InvalidArgumentException('Zenith configuration is missing. Run: php artisan vendor:publish --tag=zenith-config');
        }

        self::validateTransportConfig($config['transport'] ?? []);
        self::validateHttpConfig($config['http'] ?? []);
        self::validateHeartbeatConfig($config['heartbeat'] ?? []);
        self::validateQueueConfig($config['queues'] ?? []);
    }

    protected static function validateTransportConfig(array $transport): void
    {
        $driver = $transport['driver'] ?? 'redis';

        if (!is_string($driver)) {
            throw new InvalidArgumentException('zenith.transport.driver must be a string.');
        }

        if ($driver === '') {
            throw new InvalidArgumentException('zenith.transport.driver must be a non-empty string.');
        }
    }

    protected static function validateHttpConfig(array $http): void
    {
        $threshold = $http['slow_threshold'] ?? 1000;
        if (!is_numeric($threshold) || $threshold < 0) {
            throw new InvalidArgumentException('zenith.http.slow_threshold must be a non-negative number.');
        }

        $ignorePaths = $http['ignore_paths'] ?? [];
        if (!is_array($ignorePaths)) {
            throw new InvalidArgumentException('zenith.http.ignore_paths must be an array.');
        }

        foreach ($ignorePaths as $path) {
            if (!is_string($path)) {
                throw new InvalidArgumentException('zenith.http.ignore_paths must contain only strings.');
            }
        }
    }

    protected static function validateHeartbeatConfig(array $heartbeat): void
    {
        $interval = $heartbeat['interval'] ?? 5;
        if (!is_numeric($interval) || $interval <= 0) {
            throw new InvalidArgumentException('zenith.heartbeat.interval must be a positive number.');
        }

        $ttl = $heartbeat['ttl'] ?? 30;
        if (!is_numeric($ttl) || $ttl <= 0) {
            throw new InvalidArgumentException('zenith.heartbeat.ttl must be a positive number.');
        }

        if ($ttl < $interval) {
            throw new InvalidArgumentException('zenith.heartbeat.ttl should be >= interval to avoid stale worker keys.');
        }
    }

    protected static function validateQueueConfig(array $queues): void
    {
        $ignoreJobs = $queues['ignore_jobs'] ?? [];
        if (!is_array($ignoreJobs)) {
            throw new InvalidArgumentException('zenith.queues.ignore_jobs must be an array.');
        }

        foreach ($ignoreJobs as $job) {
            if (!is_string($job)) {
                throw new InvalidArgumentException('zenith.queues.ignore_jobs must contain only strings.');
            }
        }
    }
}
