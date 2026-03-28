<?php

namespace Gravito\Zenith\Laravel\Logging;

use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Gravito\Zenith\Laravel\Support\ChannelRegistry;
use Monolog\Logger;

class ZenithLogger
{
    public function __construct(
        protected TransportInterface $transport,
        protected ChannelRegistry $channels,
    ) {}

    public function __invoke(array $config): Logger
    {
        $logger = new Logger('zenith');

        $handler = new ZenithLogHandler(
            transport: $this->transport,
            channels: $this->channels,
            level: $config['level'] ?? 'debug',
            bubble: $config['bubble'] ?? true,
        );

        $logger->pushHandler($handler);

        return $logger;
    }
}
