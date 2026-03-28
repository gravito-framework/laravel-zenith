# Laravel Zenith

**Deep Laravel introspection for Gravito Zenith**

Laravel Zenith is a native Composer package that provides deep, real-time visibility into your Laravel application for [Gravito Zenith](https://github.com/gravito-framework/gravito-core). Unlike OS-level monitoring tools, Laravel Zenith sees what happens *inside* your application.

## Features

- 🔥 **Live Operational Logs** - Stream Laravel logs directly to Zenith UI
- 📊 **Queue Lifecycle Events** - Track job processing, completion, and failures
- 🌐 **HTTP Request Monitoring** - Monitor request performance and errors
- ❤️ **Worker Heartbeats** - Real-time worker process discovery
- ⚡ **Zero-Blocking** - Fire-and-forget architecture won't slow down your app

## Architecture

Laravel Zenith is part of the **Gravito Zenith** monitoring ecosystem. It works alongside the **Quasar Agent** to provide complete visibility:

- **Laravel Zenith** (this package): Event-driven reporter inside your Laravel app
  - Monitors job execution events (Processing/Completed/Failed)
  - Streams Laravel logs to Zenith UI
  - Reports worker heartbeat and HTTP metrics
  
- **Quasar Agent**: External daemon for infrastructure monitoring
  - Scans Redis for queue statistics (waiting/delayed counts)
  - Monitors system resources (CPU, memory, disk)
  - Provides remote control capabilities

**Key Difference**: Laravel Zenith sees **what happened** (events), Quasar Agent sees **what's the status** (statistics).

> 📖 For detailed role division and data flow, see [ARCHITECTURE.md](ARCHITECTURE.md)

## Requirements

- PHP 8.1+
- Laravel 10.x or 11.x
- Redis (default transport, or any custom transport driver)

## Installation

```bash
composer require gravito/laravel-zenith
```

### Publish Configuration

```bash
php artisan vendor:publish --tag=zenith-config
```

This creates `config/zenith.php` where you can customize behavior.

## Configuration

### 1. Transport Driver

Zenith uses a pluggable transport layer. The default driver is `redis`. Configure in `config/zenith.php`:

```php
'transport' => [
    'driver' => env('ZENITH_TRANSPORT', 'redis'),
    'connection' => env('ZENITH_REDIS_CONNECTION', 'default'),
],
```

Built-in drivers: `redis`, `null` (no-op, for testing).

### 2. Redis Connection (for Redis driver)

Add a dedicated Redis connection in `config/database.php`:

```php
'redis' => [
    'zenith' => [
        'host' => env('ZENITH_REDIS_HOST', '127.0.0.1'),
        'password' => env('ZENITH_REDIS_PASSWORD', null),
        'port' => env('ZENITH_REDIS_PORT', '6379'),
        'database' => env('ZENITH_REDIS_DB', '0'),
        'prefix' => '', // Important: no prefix or match Zenith's expectation
    ],
],
```

### 3. Environment Variables

Add to your `.env`:

```env
ZENITH_ENABLED=true
ZENITH_TRANSPORT=redis
ZENITH_REDIS_CONNECTION=zenith
```

### 4. Log Channel (Optional)

To stream Laravel logs to Zenith, add a channel in `config/logging.php`:

```php
'channels' => [
    'zenith' => [
        'driver' => 'zenith',
    ],
    
    // Or use it in a stack
    'stack' => [
        'driver' => 'stack',
        'channels' => ['daily', 'zenith'],
    ],
],
```

## Usage

### Live Logging

```php
use Illuminate\Support\Facades\Log;

Log::channel('zenith')->info('User registered', ['user_id' => 123]);
Log::channel('zenith')->error('Payment failed', ['order_id' => 456]);
```

### Queue Monitoring

Queue events are automatically monitored when enabled in `config/zenith.php`:

```php
'queues' => [
    'enabled' => true,
    'monitor_all' => true,
    'ignore_jobs' => [
        // Add job classes to ignore
    ],
],
```

### Worker Heartbeat

Run the heartbeat command as a daemon (via Supervisor):

```bash
php artisan zenith:heartbeat
```

**Supervisor Example** (`/etc/supervisor/conf.d/zenith-heartbeat.conf`):

```ini
[program:zenith-heartbeat]
process_name=%(program_name)s
command=php /path/to/artisan zenith:heartbeat
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/zenith-heartbeat.log
```

### Health Check

Verify your Zenith configuration:

```bash
php artisan zenith:check
```

## Configuration Reference

See `config/zenith.php` for all available options:

```php
return [
    'enabled' => env('ZENITH_ENABLED', true),

    'transport' => [
        'driver' => env('ZENITH_TRANSPORT', 'redis'),
        'connection' => env('ZENITH_REDIS_CONNECTION', 'default'),
    ],

    'logging' => [
        'enabled' => true,
        'level' => 'debug', // Minimum level to send
    ],

    'queues' => [
        'enabled' => true,
        'monitor_all' => true,
        'ignore_jobs' => [],
    ],

    'http' => [
        'enabled' => true,
        'ignore_paths' => ['/nova*', '/telescope*', '/horizon*'],
        'slow_threshold' => 1000, // ms
    ],
];
```

## Architecture

Laravel Zenith acts as "The Reporter" - it lives inside your application and reports events that OS-level tools cannot see:

```
┌─────────────────┐       ┌──────────────┐       ┌────────────────┐
│ Laravel App     │       │ Redis Broker │       │ Gravito Zenith │
│ (with Zenith)   │ ───▶  │ (Shared)     │ ◀──── │ Control Plane  │
└─────────────────┘       └──────────────┘       └────────────────┘
```

**Transport**: Pluggable driver system (default: Redis pub/sub). Community packages can provide custom drivers for Prometheus, Datadog, InfluxDB, etc.

**Philosophy**: Zero-blocking. All reporting is "fire-and-forget" to avoid impacting your application's performance.

## Custom Transport Drivers

Zenith's transport layer is pluggable via the Laravel Manager Pattern. To create a custom driver:

### 1. Implement `TransportInterface`

```php
use Gravito\Zenith\Laravel\Contracts\TransportInterface;

class DatadogTransport implements TransportInterface
{
    public function publish(string $topic, array $payload): void
    {
        // Send event to Datadog
    }

    public function store(string $key, array $data, int $ttl): void
    {
        // Store data with expiration
    }

    public function increment(string $key, ?int $ttl = null): void
    {
        // Increment a counter
    }

    public function ping(): bool
    {
        // Health check
        return true;
    }
}
```

### 2. Register the Driver

In your package's `ServiceProvider`:

```php
use Gravito\Zenith\Laravel\Transport\TransportManager;

public function register(): void
{
    $this->app->resolving(TransportManager::class, function ($manager) {
        $manager->extend('datadog', function () {
            return new DatadogTransport(
                config('zenith.transport.api_key')
            );
        });
    });
}
```

### 3. Activate

Users set the driver in `config/zenith.php`:

```php
'transport' => [
    'driver' => 'datadog',
    'api_key' => env('DATADOG_API_KEY'),
],
```

## Development

```bash
# Install dependencies
composer install

# Run tests
./vendor/bin/phpunit

# Run a single test
./vendor/bin/phpunit --filter=test_method_name

# Static analysis (PHPStan level 5)
./vendor/bin/phpstan analyse
```

### CI

GitHub Actions runs on every push/PR to `main`:
- **Test matrix**: PHP 8.1, 8.2, 8.3 × Laravel 10, 11
- **Static analysis**: PHPStan level 5
- **Redis**: Service container for integration tests

## License

MIT License. See [LICENSE](LICENSE) for details.

## Links

- [Gravito Zenith](https://github.com/gravito-framework/gravito-core/tree/main/packages/zenith)
- [Documentation](https://gravito.dev/docs/zenith)
- [Issues](https://github.com/gravito-framework/laravel-zenith/issues)
