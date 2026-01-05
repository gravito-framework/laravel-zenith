<?php

namespace Gravito\Zenith\Laravel\Support;

use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Fire-and-forget Redis publisher for Zenith events.
 * 
 * This class ensures that publishing to Redis never blocks the application,
 * even if Redis is unavailable or slow.
 */
class RedisPublisher
{
    protected string $connection;

    public function __construct(?string $connection = null)
    {
        $this->connection = $connection ?? config('zenith.connection', 'default');
    }

    /**
     * Publish a message to a Redis channel (fire-and-forget).
     *
     * @param string $channel
     * @param array $payload
     * @return void
     */
    public function publish(string $channel, array $payload): void
    {
        if (!config('zenith.enabled', true)) {
            return;
        }

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            Redis::connection($this->connection)->publish($channel, $json);
        } catch (Throwable $e) {
            // Silently fail to avoid disrupting the application
            // In production, you might want to log this to a separate channel
        }
    }

    /**
     * Set a key with TTL (fire-and-forget).
     *
     * @param string $key
     * @param mixed $value
     * @param int $ttl Seconds
     * @return void
     */
    public function setex(string $key, mixed $value, int $ttl): void
    {
        if (!config('zenith.enabled', true)) {
            return;
        }

        try {
            $json = is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value;
            Redis::connection($this->connection)->setex($key, $ttl, $json);
        } catch (Throwable $e) {
            // Silently fail
        }
    }

    /**
     * Increment a counter (fire-and-forget).
     *
     * @param string $key
     * @param int $ttl Optional TTL to set if key doesn't exist
     * @return void
     */
    public function incr(string $key, ?int $ttl = null): void
    {
        if (!config('zenith.enabled', true)) {
            return;
        }

        try {
            Redis::connection($this->connection)->incr($key);
            
            if ($ttl !== null) {
                Redis::connection($this->connection)->expire($key, $ttl);
            }
        } catch (Throwable $e) {
            // Silently fail
        }
    }

    /**
     * Test the Redis connection.
     *
     * @return bool
     */
    public function ping(): bool
    {
        try {
            $response = Redis::connection($this->connection)->ping();
            return $response === 'PONG' || $response === true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
