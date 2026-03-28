<?php

namespace Gravito\Zenith\Laravel\Contracts;

interface TransportInterface
{
    /**
     * Publish a message to a named channel (pub/sub semantics).
     */
    public function publish(string $channel, array $payload): void;

    /**
     * Store a value with a TTL (key-value semantics).
     */
    public function store(string $key, mixed $value, int $ttl): void;

    /**
     * Increment a counter, optionally setting a TTL.
     */
    public function increment(string $key, ?int $ttl = null): void;

    /**
     * Test the transport connection.
     */
    public function ping(): bool;
}
