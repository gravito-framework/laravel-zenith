<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Zenith Enabled
    |--------------------------------------------------------------------------
    |
    | Enable or disable Zenith monitoring globally. When disabled, the
    | NullTransport is used and all monitoring features are inactive.
    |
    */
    'enabled' => env('ZENITH_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Transport Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the transport driver used by Zenith. The default driver is
    | "redis". Community packages can register custom drivers via
    | TransportManager::extend(). Set to "null" for testing.
    |
    | Supported: "redis", "null", or any custom driver registered via extend()
    |
    */
    'transport' => [
        'driver' => env('ZENITH_TRANSPORT', 'redis'),
        'connection' => env('ZENITH_REDIS_CONNECTION', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Channel & Key Names
    |--------------------------------------------------------------------------
    |
    | Customize the channel and key names used by Zenith. Each channel
    | can be independently configured to avoid collisions with other apps.
    |
    */
    'channels' => [
        'logs'        => env('ZENITH_CHANNEL_LOGS', 'zenith:logs'),
        'worker'      => env('ZENITH_CHANNEL_WORKER', 'zenith:worker:'),
        'throughput'  => env('ZENITH_CHANNEL_THROUGHPUT', 'zenith:throughput:'),
        'http'        => env('ZENITH_CHANNEL_HTTP', 'zenith:metrics:http:'),
        'counter_ttl' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the Zenith log channel behavior. When enabled, logs sent to
    | the 'zenith' channel will be streamed to the Zenith UI in real-time.
    |
    */
    'logging' => [
        'enabled' => true,

        // Minimum log level to send (debug, info, notice, warning, error, critical, alert, emergency)
        'level' => env('ZENITH_LOG_LEVEL', 'debug'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Monitoring
    |--------------------------------------------------------------------------
    |
    | Monitor Laravel queue job lifecycle events. This provides deep visibility
    | into job processing, failures, and performance metrics.
    |
    */
    'queues' => [
        'enabled' => true,

        // Monitor all jobs by default
        'monitor_all' => true,

        // Job classes to ignore (useful for noisy internal jobs)
        'ignore_jobs' => [
            // 'App\Jobs\InternalHealthCheck',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Request Monitoring
    |--------------------------------------------------------------------------
    |
    | Track HTTP request performance, status codes, and errors. Useful for
    | identifying slow endpoints and monitoring application health.
    |
    */
    'http' => [
        'enabled' => true,

        // Paths to ignore (supports wildcards)
        'ignore_paths' => [
            '/nova*',
            '/telescope*',
            '/horizon*',
            '/_debugbar*',
            '/health',
        ],

        // Threshold in milliseconds to consider a request "slow"
        'slow_threshold' => env('ZENITH_SLOW_THRESHOLD', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker Heartbeat
    |--------------------------------------------------------------------------
    |
    | Configuration for the worker heartbeat command (zenith:heartbeat).
    | This allows Zenith to discover and monitor worker processes.
    |
    */
    'heartbeat' => [
        // How often to send heartbeat (seconds)
        'interval' => 5,

        // TTL for worker keys (seconds)
        'ttl' => 30,
    ],
];
