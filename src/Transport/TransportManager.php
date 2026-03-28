<?php

namespace Gravito\Zenith\Laravel\Transport;

use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Illuminate\Support\Manager;

class TransportManager extends Manager
{
    public function getDefaultDriver(): string
    {
        if (! config('zenith.enabled', true)) {
            return 'null';
        }

        $transport = config('zenith.transport');

        if (! is_array($transport)) {
            return 'redis';
        }

        return $transport['driver'] ?? 'redis';
    }

    protected function createRedisDriver(): TransportInterface
    {
        return new RedisTransport(
            config('zenith.transport.connection', 'default')
        );
    }

    protected function createNullDriver(): TransportInterface
    {
        return new NullTransport();
    }
}
