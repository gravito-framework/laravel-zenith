<?php

namespace Gravito\Zenith\Laravel\Support;

final class ChannelRegistry
{
    private const DEFAULTS = [
        'logs' => 'zenith:logs',
        'worker' => 'zenith:worker:',
        'throughput' => 'zenith:throughput:',
        'http' => 'zenith:metrics:http:',
        'counter_ttl' => 3600,
    ];

    public function __construct(private readonly array $config = []) {}

    public function logs(): string
    {
        return $this->config['logs'] ?? self::DEFAULTS['logs'];
    }

    public function workerKey(string $workerId): string
    {
        $prefix = $this->config['worker'] ?? self::DEFAULTS['worker'];
        return $prefix . $workerId;
    }

    public function throughputKey(int $window): string
    {
        $prefix = $this->config['throughput'] ?? self::DEFAULTS['throughput'];
        return $prefix . $window;
    }

    public function httpMetricKey(string $category, int $window): string
    {
        $prefix = $this->config['http'] ?? self::DEFAULTS['http'];
        return $prefix . $category . ':' . $window;
    }

    public function counterTtl(): int
    {
        return $this->config['counter_ttl'] ?? self::DEFAULTS['counter_ttl'];
    }
}
