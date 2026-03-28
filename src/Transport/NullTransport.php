<?php

namespace Gravito\Zenith\Laravel\Transport;

use Gravito\Zenith\Laravel\Contracts\TransportInterface;

class NullTransport implements TransportInterface
{
    public function publish(string $topic, array $payload): void
    {
    }

    public function store(string $key, array $data, int $ttl): void
    {
    }

    public function increment(string $key, ?int $ttl = null): void
    {
    }

    public function ping(): bool
    {
        return true;
    }
}
