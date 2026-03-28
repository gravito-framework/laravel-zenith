<?php

namespace Gravito\Zenith\Laravel\DataTransferObjects;

final class MetricEntry
{
    public function __construct(
        public readonly string $name,
        public readonly int $window,
        public readonly int $ttl,
    ) {}

    public function toKey(string $prefix): string
    {
        return $prefix . $this->name . ':' . $this->window;
    }
}
