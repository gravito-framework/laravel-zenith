<?php

namespace Gravito\Zenith\Laravel\Contracts;

interface TransportInterface
{
    /**
     * Publish an event to a named topic (pub/sub semantics).
     */
    public function publish(string $topic, array $payload): void;

    /**
     * Store data with a TTL (key-value semantics).
     */
    public function store(string $key, array $data, int $ttl): void;

    /**
     * Increment a counter, optionally setting a TTL.
     */
    public function increment(string $key, ?int $ttl = null): void;

    /**
     * Test the transport connection.
     */
    public function ping(): bool;
}
