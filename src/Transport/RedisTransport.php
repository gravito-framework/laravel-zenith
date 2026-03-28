<?php

namespace Gravito\Zenith\Laravel\Transport;

use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Throwable;

class RedisTransport implements TransportInterface
{
    public function __construct(
        private readonly string $connection
    ) {
    }

    public function publish(string $topic, array $payload): void
    {
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            $this->redis()->command('publish', [$topic, $json]);
        } catch (Throwable) {
            // Silently fail to avoid disrupting the application
        }
    }

    public function store(string $key, array $data, int $ttl): void
    {
        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR);
            $this->redis()->command('setex', [$key, $ttl, $json]);
        } catch (Throwable) {
            // Silently fail
        }
    }

    public function increment(string $key, ?int $ttl = null): void
    {
        try {
            $this->redis()->command('incr', [$key]);

            if ($ttl !== null) {
                $this->redis()->command('expire', [$key, $ttl]);
            }
        } catch (Throwable) {
            // Silently fail
        }
    }

    public function ping(): bool
    {
        try {
            $response = $this->redis()->command('ping');

            return $response === 'PONG' || $response === true;
        } catch (Throwable) {
            return false;
        }
    }

    protected function redis(): Connection
    {
        return Redis::connection($this->connection);
    }
}
