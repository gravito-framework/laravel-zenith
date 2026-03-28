<?php

namespace Gravito\Zenith\Laravel\DataTransferObjects;

final class HeartbeatEntry
{
    public function __construct(
        public readonly string $id,
        public readonly string $hostname,
        public readonly int $pid,
        public readonly int $uptime,
        public readonly array $queues,
        public readonly int $concurrency,
        public readonly float $memoryUsedMb,
        public readonly float $memoryPeakMb,
        public readonly string $timestamp,
        public readonly array $loadAvg,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'hostname' => $this->hostname,
            'pid' => $this->pid,
            'uptime' => $this->uptime,
            'queues' => $this->queues,
            'concurrency' => $this->concurrency,
            'memoryUsedMb' => $this->memoryUsedMb,
            'memoryPeakMb' => $this->memoryPeakMb,
            'timestamp' => $this->timestamp,
            'loadAvg' => $this->loadAvg,
        ];
    }
}
