<?php

namespace Gravito\Zenith\Laravel\Transport;

use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Throwable;

class RedisTransport implements TransportInterface
{
    protected string $connection;

    public function __construct(?string $connection = null)
    {
        $this->connection = $connection ?? config('zenith.connection', 'default');
    }

    public function publish(string $channel, array $payload): void
    {
        if (!config('zenith.enabled', true)) {
            return;
        }

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            $this->redis()->command('publish', [$channel, $json]);
        } catch (Throwable $e) {
            // Silently fail to avoid disrupting the application
        }
    }

    public function store(string $key, mixed $value, int $ttl): void
    {
        if (!config('zenith.enabled', true)) {
            return;
        }

        try {
            $json = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value;
            $this->redis()->command('setex', [$key, $ttl, $json]);
        } catch (Throwable $e) {
            // Silently fail
        }
    }

    public function increment(string $key, ?int $ttl = null): void
    {
        if (!config('zenith.enabled', true)) {
            return;
        }

        try {
            $this->redis()->command('incr', [$key]);

            if ($ttl !== null) {
                $this->redis()->command('expire', [$key, $ttl]);
            }
        } catch (Throwable $e) {
            // Silently fail
        }
    }

    public function ping(): bool
    {
        try {
            $response = $this->redis()->command('ping');
            return $response === 'PONG' || $response === true;
        } catch (Throwable $e) {
            return false;
        }
    }

    protected function redis(): Connection
    {
        return Redis::connection($this->connection);
    }
}
