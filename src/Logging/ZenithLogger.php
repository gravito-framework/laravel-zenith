<?php

namespace Gravito\Zenith\Laravel\Logging;

use Monolog\Logger;

/**
 * Custom log driver for Laravel's logging system.
 */
class ZenithLogger
{
    /**
     * Create a custom Monolog instance.
     *
     * @param array $config
     * @return Logger
     */
    public function __invoke(array $config): Logger
    {
        $logger = new Logger('zenith');
        
        $handler = new ZenithLogHandler(
            level: $config['level'] ?? 'debug',
            bubble: $config['bubble'] ?? true
        );
        
        $logger->pushHandler($handler);
        
        return $logger;
    }
}
