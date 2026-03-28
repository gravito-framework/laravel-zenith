# Transport 抽象層與資料介面通用化 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Decouple Zenith's output data interfaces from any specific remote application by introducing a Transport abstraction, formal payload DTOs, and configurable channel names.

**Architecture:** Introduce `TransportInterface` as the contract for all event publishing. Replace inline array payloads with typed DTOs (`LogEntry`, `HeartbeatEntry`, `MetricEntry`). Replace the `RedisChannels` constants class with a `ChannelRegistry` that reads channel names from config. All components receive dependencies via constructor injection from the service container.

**Tech Stack:** PHP 8.1+, Laravel 10/11, Monolog, Orchestra Testbench, Mockery, PHPUnit

---

### Task 1: Create TransportInterface

**Files:**
- Create: `src/Contracts/TransportInterface.php`
- Test: `tests/Unit/RedisTransportTest.php` (will be created in Task 2)

- [ ] **Step 1: Create the interface**

```php
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
```

Write this to `src/Contracts/TransportInterface.php`.

- [ ] **Step 2: Run PHPStan to verify the new file is valid**

Run: `./vendor/bin/phpstan analyse src/Contracts/TransportInterface.php --level=5`
Expected: 0 errors

- [ ] **Step 3: Commit**

```bash
git add src/Contracts/TransportInterface.php
git commit -m "feat: [transport] 新增 TransportInterface 契約"
```

---

### Task 2: Create RedisTransport (refactor RedisPublisher)

**Files:**
- Create: `src/Transport/RedisTransport.php`
- Modify: `tests/Unit/RedisPublisherTest.php` → rename to `tests/Unit/RedisTransportTest.php`
- Delete (later, in Task 7): `src/Support/RedisPublisher.php`

- [ ] **Step 1: Write the failing test**

Rename `tests/Unit/RedisPublisherTest.php` to `tests/Unit/RedisTransportTest.php`. Update the class name, namespace references, and import to use `RedisTransport` instead of `RedisPublisher`. Update the method names to match the new interface (`setex` → `store`, `incr` → `increment`):

```php
<?php

namespace Gravito\Zenith\Laravel\Tests\Unit;

use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Gravito\Zenith\Laravel\Transport\RedisTransport;
use Gravito\Zenith\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Redis;

class RedisTransportTest extends TestCase
{
    protected RedisTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new RedisTransport();
    }

    /** @test */
    public function it_implements_transport_interface(): void
    {
        $this->assertInstanceOf(TransportInterface::class, $this->transport);
    }

    /** @test */
    public function it_can_publish_messages_to_redis(): void
    {
        $connection = \Mockery::mock(\Illuminate\Redis\Connections\Connection::class);
        $connection->shouldReceive('command')
            ->once()
            ->with('publish', \Mockery::on(function ($args) {
                return $args[0] === 'test_channel' && is_string($args[1]);
            }))
            ->andReturn(1);

        Redis::shouldReceive('connection')
            ->once()
            ->with('default')
            ->andReturn($connection);

        $this->transport->publish('test_channel', ['message' => 'test']);
    }

    /** @test */
    public function it_does_not_publish_when_zenith_is_disabled(): void
    {
        config(['zenith.enabled' => false]);

        Redis::shouldReceive('connection')->never();

        $this->transport->publish('test_channel', ['message' => 'test']);
    }

    /** @test */
    public function it_can_store_values_with_ttl(): void
    {
        $connection = \Mockery::mock(\Illuminate\Redis\Connections\Connection::class);
        $connection->shouldReceive('command')
            ->once()
            ->with('setex', \Mockery::on(function ($args) {
                return $args[0] === 'test_key' && $args[1] === 60 && is_string($args[2]);
            }))
            ->andReturn('OK');

        Redis::shouldReceive('connection')
            ->once()
            ->with('default')
            ->andReturn($connection);

        $this->transport->store('test_key', ['data' => 'value'], 60);
    }

    /** @test */
    public function it_can_increment_counters(): void
    {
        $connection = \Mockery::mock(\Illuminate\Redis\Connections\Connection::class);
        $connection->shouldReceive('command')
            ->once()
            ->with('incr', ['test_counter'])
            ->andReturn(1);

        $connection->shouldReceive('command')
            ->once()
            ->with('expire', ['test_counter', 3600])
            ->andReturn(1);

        Redis::shouldReceive('connection')
            ->with('default')
            ->andReturn($connection);

        $this->transport->increment('test_counter', 3600);
    }

    /** @test */
    public function it_silently_fails_on_redis_errors(): void
    {
        Redis::shouldReceive('connection')
            ->once()
            ->andThrow(new \Exception('Redis connection failed'));

        $this->transport->publish('test_channel', ['message' => 'test']);

        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/RedisTransportTest.php -v`
Expected: FAIL — `RedisTransport` class not found

- [ ] **Step 3: Write the RedisTransport implementation**

Create `src/Transport/RedisTransport.php`:

```php
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/RedisTransportTest.php -v`
Expected: All 5 tests PASS

- [ ] **Step 5: Delete old test file**

```bash
rm tests/Unit/RedisPublisherTest.php
```

- [ ] **Step 6: Run PHPStan on the new file**

Run: `./vendor/bin/phpstan analyse src/Transport/RedisTransport.php --level=5`
Expected: 0 errors

- [ ] **Step 7: Commit**

```bash
git add src/Transport/RedisTransport.php tests/Unit/RedisTransportTest.php
git add -u tests/Unit/RedisPublisherTest.php
git commit -m "feat: [transport] 新增 RedisTransport 實作 TransportInterface"
```

---

### Task 3: Create Payload DTOs

**Files:**
- Create: `src/DataTransferObjects/LogEntry.php`
- Create: `src/DataTransferObjects/HeartbeatEntry.php`
- Create: `src/DataTransferObjects/MetricEntry.php`
- Create: `tests/Unit/LogEntryTest.php`
- Create: `tests/Unit/HeartbeatEntryTest.php`
- Create: `tests/Unit/MetricEntryTest.php`

- [ ] **Step 1: Write the failing test for LogEntry**

Create `tests/Unit/LogEntryTest.php`:

```php
<?php

namespace Gravito\Zenith\Laravel\Tests\Unit;

use Gravito\Zenith\Laravel\DataTransferObjects\LogEntry;
use PHPUnit\Framework\TestCase;

class LogEntryTest extends TestCase
{
    /** @test */
    public function to_array_returns_all_fields(): void
    {
        $entry = new LogEntry(
            level: 'error',
            message: 'Something failed',
            workerId: 'host-123',
            timestamp: '2026-03-28T12:00:00+00:00',
            context: ['key' => 'value'],
        );

        $result = $entry->toArray();

        $this->assertSame([
            'level' => 'error',
            'message' => 'Something failed',
            'workerId' => 'host-123',
            'timestamp' => '2026-03-28T12:00:00+00:00',
            'context' => ['key' => 'value'],
        ], $result);
    }

    /** @test */
    public function to_array_defaults_context_to_empty_array(): void
    {
        $entry = new LogEntry(
            level: 'info',
            message: 'Test',
            workerId: 'host-1',
            timestamp: '2026-03-28T12:00:00+00:00',
        );

        $this->assertSame([], $entry->toArray()['context']);
    }

    /** @test */
    public function properties_are_readonly(): void
    {
        $entry = new LogEntry(
            level: 'info',
            message: 'Test',
            workerId: 'host-1',
            timestamp: '2026-03-28T12:00:00+00:00',
        );

        $this->assertSame('info', $entry->level);
        $this->assertSame('Test', $entry->message);
        $this->assertSame('host-1', $entry->workerId);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/LogEntryTest.php -v`
Expected: FAIL — `LogEntry` class not found

- [ ] **Step 3: Implement LogEntry**

Create `src/DataTransferObjects/LogEntry.php`:

```php
<?php

namespace Gravito\Zenith\Laravel\DataTransferObjects;

final class LogEntry
{
    public function __construct(
        public readonly string $level,
        public readonly string $message,
        public readonly string $workerId,
        public readonly string $timestamp,
        public readonly array $context = [],
    ) {}

    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'message' => $this->message,
            'workerId' => $this->workerId,
            'timestamp' => $this->timestamp,
            'context' => $this->context,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/LogEntryTest.php -v`
Expected: All 3 tests PASS

- [ ] **Step 5: Write the failing test for HeartbeatEntry**

Create `tests/Unit/HeartbeatEntryTest.php`:

```php
<?php

namespace Gravito\Zenith\Laravel\Tests\Unit;

use Gravito\Zenith\Laravel\DataTransferObjects\HeartbeatEntry;
use PHPUnit\Framework\TestCase;

class HeartbeatEntryTest extends TestCase
{
    /** @test */
    public function to_array_returns_all_fields_with_language_neutral_names(): void
    {
        $entry = new HeartbeatEntry(
            id: 'web-1-12345',
            hostname: 'web-1',
            pid: 12345,
            uptime: 3600,
            queues: ['default', 'high'],
            concurrency: 4,
            memoryUsedMb: 64.5,
            memoryPeakMb: 128.0,
            timestamp: '2026-03-28T12:00:00+00:00',
            loadAvg: [1.5, 1.2, 0.9],
        );

        $result = $entry->toArray();

        $this->assertSame('web-1-12345', $result['id']);
        $this->assertSame('web-1', $result['hostname']);
        $this->assertSame(12345, $result['pid']);
        $this->assertSame(3600, $result['uptime']);
        $this->assertSame(['default', 'high'], $result['queues']);
        $this->assertSame(4, $result['concurrency']);
        $this->assertSame(64.5, $result['memoryUsedMb']);
        $this->assertSame(128.0, $result['memoryPeakMb']);
        $this->assertSame('2026-03-28T12:00:00+00:00', $result['timestamp']);
        $this->assertSame([1.5, 1.2, 0.9], $result['loadAvg']);
    }

    /** @test */
    public function to_array_does_not_contain_legacy_heap_or_rss_fields(): void
    {
        $entry = new HeartbeatEntry(
            id: 'w-1',
            hostname: 'h',
            pid: 1,
            uptime: 0,
            queues: [],
            concurrency: 1,
            memoryUsedMb: 0.0,
            memoryPeakMb: 0.0,
            timestamp: '2026-03-28T12:00:00+00:00',
            loadAvg: [0, 0, 0],
        );

        $result = $entry->toArray();

        $this->assertArrayNotHasKey('memory', $result);
        $this->assertArrayNotHasKey('heapUsed', $result);
        $this->assertArrayNotHasKey('heapTotal', $result);
        $this->assertArrayNotHasKey('rss', $result);
    }
}
```

- [ ] **Step 6: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/HeartbeatEntryTest.php -v`
Expected: FAIL — `HeartbeatEntry` class not found

- [ ] **Step 7: Implement HeartbeatEntry**

Create `src/DataTransferObjects/HeartbeatEntry.php`:

```php
<?php

namespace Gravito\Zenith\Laravel\DataTransferObjects;

final class HeartbeatEntry
{
    public function __construct(
        public readonly string $id,
        public readonly string $hostname,
        public readonly int $pid,
        public readonly int $uptime,
        public readonly array $queues,
        public readonly int $concurrency,
        public readonly float $memoryUsedMb,
        public readonly float $memoryPeakMb,
        public readonly string $timestamp,
        public readonly array $loadAvg,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'hostname' => $this->hostname,
            'pid' => $this->pid,
            'uptime' => $this->uptime,
            'queues' => $this->queues,
            'concurrency' => $this->concurrency,
            'memoryUsedMb' => $this->memoryUsedMb,
            'memoryPeakMb' => $this->memoryPeakMb,
            'timestamp' => $this->timestamp,
            'loadAvg' => $this->loadAvg,
        ];
    }
}
```

- [ ] **Step 8: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/HeartbeatEntryTest.php -v`
Expected: All 2 tests PASS

- [ ] **Step 9: Write the failing test for MetricEntry**

Create `tests/Unit/MetricEntryTest.php`:

```php
<?php

namespace Gravito\Zenith\Laravel\Tests\Unit;

use Gravito\Zenith\Laravel\DataTransferObjects\MetricEntry;
use PHPUnit\Framework\TestCase;

class MetricEntryTest extends TestCase
{
    /** @test */
    public function to_key_combines_prefix_name_and_window(): void
    {
        $entry = new MetricEntry(
            name: 'http_2xx',
            window: 28800,
            ttl: 3600,
        );

        $result = $entry->toKey('zenith:metrics:http:');

        $this->assertSame('zenith:metrics:http:http_2xx:28800', $result);
    }

    /** @test */
    public function to_key_works_with_custom_prefix(): void
    {
        $entry = new MetricEntry(
            name: 'job_throughput',
            window: 12345,
            ttl: 7200,
        );

        $result = $entry->toKey('myapp:counters:');

        $this->assertSame('myapp:counters:job_throughput:12345', $result);
    }

    /** @test */
    public function properties_are_accessible(): void
    {
        $entry = new MetricEntry(
            name: 'http_slow',
            window: 100,
            ttl: 3600,
        );

        $this->assertSame('http_slow', $entry->name);
        $this->assertSame(100, $entry->window);
        $this->assertSame(3600, $entry->ttl);
    }
}
```

- [ ] **Step 10: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/MetricEntryTest.php -v`
Expected: FAIL — `MetricEntry` class not found

- [ ] **Step 11: Implement MetricEntry**

Create `src/DataTransferObjects/MetricEntry.php`:

```php
<?php

namespace Gravito\Zenith\Laravel\DataTransferObjects;

final class MetricEntry
{
    public function __construct(
        public readonly string $name,
        public readonly int $window,
        public readonly int $ttl,
    ) {}

    public function toKey(string $prefix): string
    {
        return $prefix . $this->name . ':' . $this->window;
    }
}
```

- [ ] **Step 12: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/MetricEntryTest.php -v`
Expected: All 3 tests PASS

- [ ] **Step 13: Run PHPStan on all DTOs**

Run: `./vendor/bin/phpstan analyse src/DataTransferObjects/ --level=5`
Expected: 0 errors

- [ ] **Step 14: Commit**

```bash
git add src/DataTransferObjects/ tests/Unit/LogEntryTest.php tests/Unit/HeartbeatEntryTest.php tests/Unit/MetricEntryTest.php
git commit -m "feat: [dto] 新增 LogEntry、HeartbeatEntry、MetricEntry 資料傳輸物件"
```

---

### Task 4: Create ChannelRegistry

**Files:**
- Create: `src/Support/ChannelRegistry.php`
- Create: `tests/Unit/ChannelRegistryTest.php`
- Modify: `config/zenith.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ChannelRegistryTest.php`:

```php
<?php

namespace Gravito\Zenith\Laravel\Tests\Unit;

use Gravito\Zenith\Laravel\Support\ChannelRegistry;
use PHPUnit\Framework\TestCase;

class ChannelRegistryTest extends TestCase
{
    /** @test */
    public function logs_returns_configured_channel_name(): void
    {
        $registry = new ChannelRegistry(['logs' => 'myapp:logs']);

        $this->assertSame('myapp:logs', $registry->logs());
    }

    /** @test */
    public function logs_returns_default_when_not_configured(): void
    {
        $registry = new ChannelRegistry([]);

        $this->assertSame('zenith:logs', $registry->logs());
    }

    /** @test */
    public function worker_key_appends_worker_id(): void
    {
        $registry = new ChannelRegistry(['worker' => 'myapp:worker:']);

        $this->assertSame('myapp:worker:web-1-123', $registry->workerKey('web-1-123'));
    }

    /** @test */
    public function worker_key_uses_default_prefix(): void
    {
        $registry = new ChannelRegistry([]);

        $this->assertSame('zenith:worker:abc', $registry->workerKey('abc'));
    }

    /** @test */
    public function throughput_key_appends_window(): void
    {
        $registry = new ChannelRegistry(['throughput' => 'custom:throughput:']);

        $this->assertSame('custom:throughput:28800', $registry->throughputKey(28800));
    }

    /** @test */
    public function throughput_key_uses_default_prefix(): void
    {
        $registry = new ChannelRegistry([]);

        $this->assertSame('zenith:throughput:999', $registry->throughputKey(999));
    }

    /** @test */
    public function http_metric_key_appends_category_and_window(): void
    {
        $registry = new ChannelRegistry(['http' => 'app:http:']);

        $this->assertSame('app:http:2xx:28800', $registry->httpMetricKey('2xx', 28800));
    }

    /** @test */
    public function http_metric_key_uses_default_prefix(): void
    {
        $registry = new ChannelRegistry([]);

        $this->assertSame('zenith:metrics:http:5xx:100', $registry->httpMetricKey('5xx', 100));
    }

    /** @test */
    public function counter_ttl_returns_configured_value(): void
    {
        $registry = new ChannelRegistry(['counter_ttl' => 7200]);

        $this->assertSame(7200, $registry->counterTtl());
    }

    /** @test */
    public function counter_ttl_returns_default_3600(): void
    {
        $registry = new ChannelRegistry([]);

        $this->assertSame(3600, $registry->counterTtl());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/ChannelRegistryTest.php -v`
Expected: FAIL — `ChannelRegistry` class not found

- [ ] **Step 3: Implement ChannelRegistry**

Create `src/Support/ChannelRegistry.php`:

```php
<?php

namespace Gravito\Zenith\Laravel\Support;

final class ChannelRegistry
{
    private const DEFAULTS = [
        'logs' => 'zenith:logs',
        'worker' => 'zenith:worker:',
        'throughput' => 'zenith:throughput:',
        'http' => 'zenith:metrics:http:',
        'counter_ttl' => 3600,
    ];

    public function __construct(private readonly array $config = []) {}

    public function logs(): string
    {
        return $this->config['logs'] ?? self::DEFAULTS['logs'];
    }

    public function workerKey(string $workerId): string
    {
        $prefix = $this->config['worker'] ?? self::DEFAULTS['worker'];
        return $prefix . $workerId;
    }

    public function throughputKey(int $window): string
    {
        $prefix = $this->config['throughput'] ?? self::DEFAULTS['throughput'];
        return $prefix . $window;
    }

    public function httpMetricKey(string $category, int $window): string
    {
        $prefix = $this->config['http'] ?? self::DEFAULTS['http'];
        return $prefix . $category . ':' . $window;
    }

    public function counterTtl(): int
    {
        return $this->config['counter_ttl'] ?? self::DEFAULTS['counter_ttl'];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/ChannelRegistryTest.php -v`
Expected: All 10 tests PASS

- [ ] **Step 5: Add channels config block to zenith.php**

In `config/zenith.php`, add after the `'connection'` block (before the `'logging'` block):

```php
    /*
    |--------------------------------------------------------------------------
    | Channel & Key Names
    |--------------------------------------------------------------------------
    |
    | Customize the Redis channel and key names used by Zenith. Each channel
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
```

- [ ] **Step 6: Run PHPStan**

Run: `./vendor/bin/phpstan analyse src/Support/ChannelRegistry.php --level=5`
Expected: 0 errors

- [ ] **Step 7: Commit**

```bash
git add src/Support/ChannelRegistry.php tests/Unit/ChannelRegistryTest.php config/zenith.php
git commit -m "feat: [channels] 新增 ChannelRegistry 可配置 channel 名稱"
```

---

### Task 5: Update ZenithServiceProvider (DI bindings)

**Files:**
- Modify: `src/ZenithServiceProvider.php`
- Modify: `src/Logging/ZenithLogger.php`
- Modify: `tests/Feature/ZenithServiceProviderTest.php`

- [ ] **Step 1: Write the failing test for DI bindings**

Add new tests to `tests/Feature/ZenithServiceProviderTest.php`. Add the following imports at the top:

```php
use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Gravito\Zenith\Laravel\Support\ChannelRegistry;
use Gravito\Zenith\Laravel\Transport\RedisTransport;
```

Add these test methods to the class:

```php
    /** @test */
    public function transport_interface_is_bound_as_singleton(): void
    {
        $instance1 = $this->app->make(TransportInterface::class);
        $instance2 = $this->app->make(TransportInterface::class);

        $this->assertInstanceOf(RedisTransport::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    /** @test */
    public function channel_registry_is_bound_as_singleton(): void
    {
        $instance1 = $this->app->make(ChannelRegistry::class);
        $instance2 = $this->app->make(ChannelRegistry::class);

        $this->assertInstanceOf(ChannelRegistry::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    /** @test */
    public function channel_registry_uses_config_values(): void
    {
        config(['zenith.channels.logs' => 'custom:logs']);

        // Re-register to pick up new config
        $provider = new \Gravito\Zenith\Laravel\ZenithServiceProvider($this->app);
        $provider->register();

        $registry = $this->app->make(ChannelRegistry::class);
        $this->assertSame('custom:logs', $registry->logs());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit tests/Feature/ZenithServiceProviderTest.php --filter="transport_interface_is_bound|channel_registry_is_bound|channel_registry_uses_config" -v`
Expected: FAIL — bindings not registered yet

- [ ] **Step 3: Update ZenithServiceProvider::register()**

Replace the contents of `src/ZenithServiceProvider.php` with:

```php
<?php

namespace Gravito\Zenith\Laravel;

use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Gravito\Zenith\Laravel\Console\ZenithCheckCommand;
use Gravito\Zenith\Laravel\Console\ZenithHeartbeatCommand;
use Gravito\Zenith\Laravel\Logging\ZenithLogger;
use Gravito\Zenith\Laravel\Queue\ZenithQueueSubscriber;
use Gravito\Zenith\Laravel\Support\ChannelRegistry;
use Gravito\Zenith\Laravel\Support\ConfigValidator;
use Gravito\Zenith\Laravel\Transport\RedisTransport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class ZenithServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/zenith.php',
            'zenith'
        );

        $this->app->singleton(TransportInterface::class, function ($app) {
            return new RedisTransport(config('zenith.connection', 'default'));
        });

        $this->app->singleton(ChannelRegistry::class, function ($app) {
            return new ChannelRegistry(config('zenith.channels', []));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__ . '/../config/zenith.php' => config_path('zenith.php'),
        ], 'zenith-config');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                ZenithCheckCommand::class,
                ZenithHeartbeatCommand::class,
            ]);
        }

        // Validate configuration
        if (config('zenith.enabled', true)) {
            ConfigValidator::validate();
        }

        // Register custom log driver
        $this->registerLogDriver();

        // Register queue event subscriber
        $this->registerQueueSubscriber();
    }

    /**
     * Register the Zenith log driver.
     */
    protected function registerLogDriver(): void
    {
        if (!config('zenith.enabled', true) || !config('zenith.logging.enabled', true)) {
            return;
        }

        Log::extend('zenith', function ($app, array $config) {
            return (new ZenithLogger(
                $app->make(TransportInterface::class),
                $app->make(ChannelRegistry::class),
            ))($config);
        });
    }

    /**
     * Register the queue event subscriber.
     */
    protected function registerQueueSubscriber(): void
    {
        if (!config('zenith.enabled', true) || !config('zenith.queues.enabled', true)) {
            return;
        }

        $this->app['events']->subscribe(ZenithQueueSubscriber::class);
    }
}
```

- [ ] **Step 4: Update ZenithLogger to accept injected dependencies**

Replace `src/Logging/ZenithLogger.php` with:

```php
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
```

Note: `ZenithLogHandler` will be updated in Task 6 to accept these constructor args. This step will cause a temporary type error that Task 6 resolves.

- [ ] **Step 5: Run the new DI binding tests**

Run: `./vendor/bin/phpunit tests/Feature/ZenithServiceProviderTest.php --filter="transport_interface_is_bound|channel_registry_is_bound|channel_registry_uses_config" -v`
Expected: All 3 tests PASS

- [ ] **Step 6: Commit**

```bash
git add src/ZenithServiceProvider.php src/Logging/ZenithLogger.php tests/Feature/ZenithServiceProviderTest.php
git commit -m "feat: [di] ServiceProvider 註冊 TransportInterface 與 ChannelRegistry singleton"
```

---

### Task 6: Update ZenithLogHandler

**Files:**
- Modify: `src/Logging/ZenithLogHandler.php`
- Modify: `tests/Unit/ZenithLogHandlerTest.php`

- [ ] **Step 1: Update the test to use mock TransportInterface**

Replace `tests/Unit/ZenithLogHandlerTest.php` with:

```php
<?php

namespace Gravito\Zenith\Laravel\Tests\Unit;

use DateTimeImmutable;
use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Gravito\Zenith\Laravel\Logging\ZenithLogHandler;
use Gravito\Zenith\Laravel\Logging\ZenithLogger;
use Gravito\Zenith\Laravel\Support\ChannelRegistry;
use Gravito\Zenith\Laravel\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Logger;

class ZenithLogHandlerTest extends TestCase
{
    protected ZenithLogHandler $handler;
    protected MockInterface $transport;
    protected ChannelRegistry $channels;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = Mockery::mock(TransportInterface::class);
        $this->channels = new ChannelRegistry([]);
        $this->handler = new ZenithLogHandler(
            transport: $this->transport,
            channels: $this->channels,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeRecord(
        Level $level = Level::Info,
        string $message = 'test message',
        array $context = []
    ): LogRecord {
        return new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'test',
            level: $level,
            message: $message,
            context: $context,
        );
    }

    /** @test */
    public function write_publishes_log_entry_to_transport(): void
    {
        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(function (array $payload) {
                    return $payload['level'] === 'info'
                        && $payload['message'] === 'hello world'
                        && isset($payload['workerId'])
                        && isset($payload['timestamp'])
                        && is_array($payload['context']);
                })
            );

        $this->handler->handle($this->makeRecord(Level::Info, 'hello world'));
    }

    /** @test */
    public function write_does_nothing_when_logging_is_disabled(): void
    {
        config(['zenith.logging.enabled' => false]);

        $this->transport->shouldReceive('publish')->never();

        $this->handler->handle($this->makeRecord(Level::Error, 'should not publish'));
    }

    /** @test */
    public function map_level_maps_error_to_error(): void
    {
        $this->assertLevelMapsTo('error', Level::Error);
    }

    /** @test */
    public function map_level_maps_critical_to_error(): void
    {
        $this->assertLevelMapsTo('error', Level::Critical);
    }

    /** @test */
    public function map_level_maps_alert_to_error(): void
    {
        $this->assertLevelMapsTo('error', Level::Alert);
    }

    /** @test */
    public function map_level_maps_emergency_to_error(): void
    {
        $this->assertLevelMapsTo('error', Level::Emergency);
    }

    /** @test */
    public function map_level_maps_warning_to_warn(): void
    {
        $this->assertLevelMapsTo('warn', Level::Warning);
    }

    /** @test */
    public function map_level_maps_info_to_info(): void
    {
        $this->assertLevelMapsTo('info', Level::Info);
    }

    /** @test */
    public function map_level_maps_debug_to_info(): void
    {
        $this->assertLevelMapsTo('info', Level::Debug);
    }

    /** @test */
    public function map_level_maps_notice_to_info(): void
    {
        $this->assertLevelMapsTo('info', Level::Notice);
    }

    private function assertLevelMapsTo(string $expected, Level $level): void
    {
        $capturedLevel = null;

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(function (array $payload) use (&$capturedLevel) {
                    $capturedLevel = $payload['level'] ?? null;
                    return true;
                })
            );

        $this->handler->handle($this->makeRecord($level));

        $this->assertSame($expected, $capturedLevel);
    }

    /** @test */
    public function write_includes_queue_context_in_payload(): void
    {
        $capturedPayload = null;

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(function (array $payload) use (&$capturedPayload) {
                    $capturedPayload = $payload;
                    return true;
                })
            );

        $record = $this->makeRecord(Level::Info, 'job processed', ['queue' => 'emails']);
        $this->handler->handle($record);

        $this->assertArrayHasKey('context', $capturedPayload);
        $this->assertSame('emails', $capturedPayload['context']['queue']);
    }

    /** @test */
    public function write_silently_catches_exceptions_and_never_throws(): void
    {
        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->andThrow(new \RuntimeException('Transport is down'));

        $this->handler->handle($this->makeRecord(Level::Critical, 'boom'));

        $this->assertTrue(true);
    }

    /** @test */
    public function write_uses_custom_channel_name(): void
    {
        $customChannels = new ChannelRegistry(['logs' => 'custom:logs']);
        $handler = new ZenithLogHandler(
            transport: $this->transport,
            channels: $customChannels,
        );

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with('custom:logs', Mockery::any());

        $handler->handle($this->makeRecord(Level::Info, 'test'));
    }

    /** @test */
    public function zenith_logger_creates_handler_with_injected_dependencies(): void
    {
        $factory = new ZenithLogger($this->transport, $this->channels);

        $logger = $factory(['level' => 'debug', 'bubble' => true]);

        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertSame('zenith', $logger->getName());
        $this->assertNotEmpty($logger->getHandlers());
        $this->assertInstanceOf(ZenithLogHandler::class, $logger->getHandlers()[0]);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit tests/Unit/ZenithLogHandlerTest.php -v`
Expected: FAIL — `ZenithLogHandler` constructor doesn't accept `transport`/`channels` yet

- [ ] **Step 3: Update ZenithLogHandler implementation**

Replace `src/Logging/ZenithLogHandler.php` with:

```php
<?php

namespace Gravito\Zenith\Laravel\Logging;

use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Gravito\Zenith\Laravel\DataTransferObjects\LogEntry;
use Gravito\Zenith\Laravel\Support\ChannelRegistry;
use Gravito\Zenith\Laravel\Support\GeneratesWorkerId;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Monolog\Level;
use Throwable;

class ZenithLogHandler extends AbstractProcessingHandler
{
    use GeneratesWorkerId;

    protected string $workerId;

    public function __construct(
        protected TransportInterface $transport,
        protected ChannelRegistry $channels,
        $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);
        $this->workerId = $this->generateWorkerId();
    }

    protected function write(LogRecord $record): void
    {
        try {
            if (!config('zenith.logging.enabled', true)) {
                return;
            }

            $entry = new LogEntry(
                level: $this->mapLevel($record->level),
                message: $record->message,
                workerId: $this->workerId,
                timestamp: $record->datetime->format('c'),
                context: $record->context,
            );

            $this->transport->publish($this->channels->logs(), $entry->toArray());
        } catch (Throwable $e) {
            // Silently fail to avoid breaking the logging pipeline
        }
    }

    protected function mapLevel(Level $level): string
    {
        return match ($level->value) {
            Level::Error->value, Level::Critical->value, Level::Alert->value, Level::Emergency->value => 'error',
            Level::Warning->value => 'warn',
            default => 'info',
        };
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/ZenithLogHandlerTest.php -v`
Expected: All tests PASS

- [ ] **Step 5: Commit**

```bash
git add src/Logging/ZenithLogHandler.php tests/Unit/ZenithLogHandlerTest.php
git commit -m "refactor: [log-handler] ZenithLogHandler 改用 TransportInterface + ChannelRegistry + LogEntry"
```

---

### Task 7: Update ZenithQueueSubscriber

**Files:**
- Modify: `src/Queue/ZenithQueueSubscriber.php`
- Modify: `tests/Unit/ZenithQueueSubscriberTest.php`

- [ ] **Step 1: Update tests to use TransportInterface mock and ChannelRegistry**

Replace `tests/Unit/ZenithQueueSubscriberTest.php` with:

```php
<?php

namespace Gravito\Zenith\Laravel\Tests\Unit;

use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Gravito\Zenith\Laravel\Queue\ZenithQueueSubscriber;
use Gravito\Zenith\Laravel\Support\ChannelRegistry;
use Gravito\Zenith\Laravel\Tests\TestCase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Mockery;
use Mockery\MockInterface;

class ZenithQueueSubscriberTest extends TestCase
{
    protected ZenithQueueSubscriber $subscriber;
    protected MockInterface $transport;
    protected ChannelRegistry $channels;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = Mockery::mock(TransportInterface::class);
        $this->channels = new ChannelRegistry([]);

        $this->subscriber = new ZenithQueueSubscriber(
            $this->transport,
            $this->channels,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function handle_job_processing_publishes_info_log_to_transport(): void
    {
        config(['zenith.queues.monitor_all' => true]);

        $job = $this->makeJob('App\\Jobs\\SendEmailJob', 'default');

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(function (array $payload) {
                    return $payload['level'] === 'info'
                        && $payload['message'] === 'Processing SendEmailJob'
                        && $payload['context']['queue'] === 'default'
                        && isset($payload['workerId'])
                        && isset($payload['timestamp']);
                })
            );

        $event = new JobProcessing('redis', $job);
        $this->subscriber->handleJobProcessing($event);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function handle_job_processing_does_not_publish_when_job_is_ignored(): void
    {
        config([
            'zenith.queues.monitor_all' => true,
            'zenith.queues.ignore_jobs' => ['SendEmailJob'],
        ]);

        $subscriber = new ZenithQueueSubscriber($this->transport, $this->channels);

        $this->transport->shouldReceive('publish')->never();

        $job = $this->makeJob('App\\Jobs\\SendEmailJob', 'default');
        $event = new JobProcessing('redis', $job);
        $subscriber->handleJobProcessing($event);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function handle_job_processed_publishes_success_log_and_increments_throughput(): void
    {
        config(['zenith.queues.monitor_all' => true]);

        $job = $this->makeJob('App\\Jobs\\ProcessOrderJob', 'high');

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(function (array $payload) {
                    return $payload['level'] === 'success'
                        && $payload['message'] === 'Completed ProcessOrderJob'
                        && $payload['context']['queue'] === 'high';
                })
            );

        $this->transport
            ->shouldReceive('increment')
            ->once()
            ->with(
                Mockery::on(fn (string $key) => str_starts_with($key, 'zenith:throughput:')),
                3600
            );

        $event = new JobProcessed('redis', $job);
        $this->subscriber->handleJobProcessed($event);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function handle_job_processed_does_not_publish_when_job_is_ignored(): void
    {
        config([
            'zenith.queues.monitor_all' => true,
            'zenith.queues.ignore_jobs' => ['ProcessOrderJob'],
        ]);

        $subscriber = new ZenithQueueSubscriber($this->transport, $this->channels);

        $this->transport->shouldReceive('publish')->never();
        $this->transport->shouldReceive('increment')->never();

        $job = $this->makeJob('App\\Jobs\\ProcessOrderJob', 'high');
        $event = new JobProcessed('redis', $job);
        $subscriber->handleJobProcessed($event);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function handle_job_failed_publishes_error_log_with_exception_message(): void
    {
        config(['zenith.queues.monitor_all' => true]);

        $job = $this->makeJob('App\\Jobs\\ImportCsvJob', 'default');
        $exception = new \RuntimeException('Disk quota exceeded');

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(function (array $payload) {
                    return $payload['level'] === 'error'
                        && $payload['message'] === 'Failed ImportCsvJob: Disk quota exceeded'
                        && $payload['context']['queue'] === 'default';
                })
            );

        $this->transport
            ->shouldReceive('increment')
            ->once()
            ->with(
                Mockery::on(fn (string $key) => str_starts_with($key, 'zenith:throughput:')),
                3600
            );

        $event = new JobFailed('redis', $job, $exception);
        $this->subscriber->handleJobFailed($event);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function handle_job_failed_does_not_publish_when_job_is_ignored(): void
    {
        config([
            'zenith.queues.monitor_all' => true,
            'zenith.queues.ignore_jobs' => ['ImportCsvJob'],
        ]);

        $subscriber = new ZenithQueueSubscriber($this->transport, $this->channels);

        $this->transport->shouldReceive('publish')->never();
        $this->transport->shouldReceive('increment')->never();

        $job = $this->makeJob('App\\Jobs\\ImportCsvJob', 'default');
        $exception = new \RuntimeException('Disk quota exceeded');
        $event = new JobFailed('redis', $job, $exception);
        $subscriber->handleJobFailed($event);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function should_ignore_returns_true_for_jobs_matching_ignore_list(): void
    {
        config([
            'zenith.queues.monitor_all' => true,
            'zenith.queues.ignore_jobs' => ['SendEmailJob', 'Cleanup*'],
        ]);

        $subscriber = new ZenithQueueSubscriber($this->transport, $this->channels);

        $this->transport->shouldReceive('publish')->never();

        $job = $this->makeJob('App\\Jobs\\SendEmailJob', 'default');
        $event = new JobProcessing('redis', $job);
        $subscriber->handleJobProcessing($event);

        $job2 = $this->makeJob('App\\Jobs\\CleanupOldRecordsJob', 'default');
        $event2 = new JobProcessing('redis', $job2);
        $subscriber->handleJobProcessing($event2);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function should_ignore_returns_true_when_monitor_all_is_disabled(): void
    {
        config(['zenith.queues.monitor_all' => false]);

        $this->transport->shouldReceive('publish')->never();

        $job = $this->makeJob('App\\Jobs\\AnyJob', 'default');
        $event = new JobProcessing('redis', $job);
        $this->subscriber->handleJobProcessing($event);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function get_job_name_extracts_class_basename_from_job_payload(): void
    {
        config(['zenith.queues.monitor_all' => true]);

        $job = $this->makeJob('App\\Jobs\\Billing\\GenerateInvoiceJob', 'default');

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(fn (array $p) => $p['message'] === 'Processing GenerateInvoiceJob')
            );

        $event = new JobProcessing('redis', $job);
        $this->subscriber->handleJobProcessing($event);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function get_job_name_returns_unknown_job_when_display_name_is_missing(): void
    {
        config(['zenith.queues.monitor_all' => true]);

        $job = $this->makeJobWithPayload([], 'default');

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(fn (array $p) => $p['message'] === 'Processing Unknown Job')
            );

        $event = new JobProcessing('redis', $job);
        $this->subscriber->handleJobProcessing($event);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function get_job_name_returns_unknown_job_when_display_name_is_empty_string(): void
    {
        config(['zenith.queues.monitor_all' => true]);

        $job = $this->makeJobWithPayload(['displayName' => ''], 'default');

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(fn (array $p) => $p['message'] === 'Processing Unknown Job')
            );

        $event = new JobProcessing('redis', $job);
        $this->subscriber->handleJobProcessing($event);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function get_job_name_returns_unknown_job_when_payload_throws(): void
    {
        config(['zenith.queues.monitor_all' => true]);

        $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andThrow(new \RuntimeException('corrupt payload'));
        $job->shouldReceive('getQueue')->andReturn('default');

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(fn (array $p) => $p['message'] === 'Processing Unknown Job')
            );

        $event = new JobProcessing('redis', $job);
        $this->subscriber->handleJobProcessing($event);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function subscribe_does_nothing_when_queues_are_disabled(): void
    {
        config(['zenith.queues.enabled' => false]);

        $dispatcher = Mockery::mock(\Illuminate\Events\Dispatcher::class);
        $dispatcher->shouldReceive('listen')->never();

        $this->subscriber->subscribe($dispatcher);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function subscribe_registers_all_three_listeners_when_queues_are_enabled(): void
    {
        config(['zenith.queues.enabled' => true]);

        $registeredListeners = [];

        $dispatcher = Mockery::mock(\Illuminate\Events\Dispatcher::class);
        $dispatcher
            ->shouldReceive('listen')
            ->times(3)
            ->andReturnUsing(function (string $event) use (&$registeredListeners) {
                $registeredListeners[] = $event;
            });

        $this->subscriber->subscribe($dispatcher);

        $this->assertContains(JobProcessing::class, $registeredListeners);
        $this->assertContains(JobProcessed::class, $registeredListeners);
        $this->assertContains(JobFailed::class, $registeredListeners);
    }

    /** @test */
    public function it_uses_custom_channel_names(): void
    {
        config(['zenith.queues.monitor_all' => true]);

        $customChannels = new ChannelRegistry([
            'logs' => 'custom:logs',
            'throughput' => 'custom:throughput:',
        ]);
        $subscriber = new ZenithQueueSubscriber($this->transport, $customChannels);

        $job = $this->makeJob('App\\Jobs\\TestJob', 'default');

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with('custom:logs', Mockery::any());

        $this->transport
            ->shouldReceive('increment')
            ->once()
            ->with(
                Mockery::on(fn (string $key) => str_starts_with($key, 'custom:throughput:')),
                Mockery::any()
            );

        $event = new JobProcessed('redis', $job);
        $subscriber->handleJobProcessed($event);

        $this->addToAssertionCount(1);
    }

    private function makeJob(string $fullyQualifiedClassName, string $queue): \Illuminate\Contracts\Queue\Job
    {
        return $this->makeJobWithPayload(['displayName' => $fullyQualifiedClassName], $queue);
    }

    private function makeJobWithPayload(array $payload, string $queue): \Illuminate\Contracts\Queue\Job
    {
        $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andReturn($payload);
        $job->shouldReceive('getQueue')->andReturn($queue);

        return $job;
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit tests/Unit/ZenithQueueSubscriberTest.php -v`
Expected: FAIL — `ZenithQueueSubscriber` constructor doesn't accept `TransportInterface`/`ChannelRegistry`

- [ ] **Step 3: Update ZenithQueueSubscriber implementation**

Replace `src/Queue/ZenithQueueSubscriber.php` with:

```php
<?php

namespace Gravito\Zenith\Laravel\Queue;

use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Gravito\Zenith\Laravel\DataTransferObjects\LogEntry;
use Gravito\Zenith\Laravel\Support\ChannelRegistry;
use Gravito\Zenith\Laravel\Support\GeneratesWorkerId;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

class ZenithQueueSubscriber
{
    use GeneratesWorkerId;

    protected string $workerId;
    protected array $ignoreJobs;

    public function __construct(
        protected TransportInterface $transport,
        protected ChannelRegistry $channels,
    ) {
        $this->workerId = $this->generateWorkerId();
        $this->ignoreJobs = config('zenith.queues.ignore_jobs', []);
    }

    public function subscribe($events): void
    {
        if (!config('zenith.queues.enabled', true)) {
            return;
        }

        $events->listen(JobProcessing::class, [$this, 'handleJobProcessing']);
        $events->listen(JobProcessed::class, [$this, 'handleJobProcessed']);
        $events->listen(JobFailed::class, [$this, 'handleJobFailed']);
    }

    public function handleJobProcessing(JobProcessing $event): void
    {
        $jobName = $this->getJobName($event->job);

        if ($this->shouldIgnore($jobName)) {
            return;
        }

        $this->publishLog('info', "Processing {$jobName}", $event->job->getQueue());
    }

    public function handleJobProcessed(JobProcessed $event): void
    {
        $jobName = $this->getJobName($event->job);

        if ($this->shouldIgnore($jobName)) {
            return;
        }

        $this->publishLog('success', "Completed {$jobName}", $event->job->getQueue());

        $minute = (int) floor(time() / 60);
        $this->transport->increment(
            $this->channels->throughputKey($minute),
            $this->channels->counterTtl(),
        );
    }

    public function handleJobFailed(JobFailed $event): void
    {
        $jobName = $this->getJobName($event->job);

        if ($this->shouldIgnore($jobName)) {
            return;
        }

        $errorMessage = $event->exception->getMessage();
        $this->publishLog('error', "Failed {$jobName}: {$errorMessage}", $event->job->getQueue());

        $minute = (int) floor(time() / 60);
        $this->transport->increment(
            $this->channels->throughputKey($minute),
            $this->channels->counterTtl(),
        );
    }

    protected function publishLog(string $level, string $message, ?string $queue = null): void
    {
        $entry = new LogEntry(
            level: $level,
            message: $message,
            workerId: $this->workerId,
            timestamp: now()->toIso8601String(),
            context: $queue ? ['queue' => $queue] : [],
        );

        $this->transport->publish($this->channels->logs(), $entry->toArray());
    }

    protected function getJobName($job): string
    {
        try {
            $payload = $job->payload();
            $displayName = $payload['displayName'] ?? null;

            if (!is_string($displayName) || $displayName === '') {
                return 'Unknown Job';
            }

            return class_basename($displayName);
        } catch (\Throwable $e) {
            return 'Unknown Job';
        }
    }

    protected function shouldIgnore(string $jobName): bool
    {
        if (!config('zenith.queues.monitor_all', true)) {
            return true;
        }

        foreach ($this->ignoreJobs as $pattern) {
            if (fnmatch($pattern, $jobName)) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/ZenithQueueSubscriberTest.php -v`
Expected: All tests PASS

- [ ] **Step 5: Commit**

```bash
git add src/Queue/ZenithQueueSubscriber.php tests/Unit/ZenithQueueSubscriberTest.php
git commit -m "refactor: [queue] ZenithQueueSubscriber 改用 TransportInterface + ChannelRegistry + LogEntry"
```

---

### Task 8: Update RecordRequestMetrics

**Files:**
- Modify: `src/Http/Middleware/RecordRequestMetrics.php`
- Modify: `tests/Unit/RecordRequestMetricsTest.php`

- [ ] **Step 1: Update tests to use TransportInterface mock**

Replace `tests/Unit/RecordRequestMetricsTest.php` with:

```php
<?php

namespace Gravito\Zenith\Laravel\Tests\Unit;

use Closure;
use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Gravito\Zenith\Laravel\Http\Middleware\RecordRequestMetrics;
use Gravito\Zenith\Laravel\Support\ChannelRegistry;
use Gravito\Zenith\Laravel\Tests\TestCase;
use Illuminate\Http\Request;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\Response;

class RecordRequestMetricsTest extends TestCase
{
    protected MockInterface $transport;
    protected ChannelRegistry $channels;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = Mockery::mock(TransportInterface::class);
        $this->channels = new ChannelRegistry([]);

        config([
            'zenith.enabled' => true,
            'zenith.http.enabled' => true,
            'zenith.http.ignore_paths' => [],
            'zenith.http.slow_threshold' => 1000,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeMiddleware(): RecordRequestMetrics
    {
        return new RecordRequestMetrics($this->transport, $this->channels);
    }

    private function makeRequest(string $path = 'api/test', string $method = 'GET'): Request
    {
        return Request::create('/' . ltrim($path, '/'), $method);
    }

    private function makeResponse(int $status = 200): Response
    {
        return new Response('', $status);
    }

    private function nextReturning(Response $response): Closure
    {
        return fn (Request $request) => $response;
    }

    /** @test */
    public function it_passes_request_through_when_zenith_is_disabled(): void
    {
        config(['zenith.enabled' => false]);

        $this->transport->shouldReceive('publish')->never();

        $request = $this->makeRequest();
        $response = $this->makeResponse(200);
        $middleware = $this->makeMiddleware();

        $result = $middleware->handle($request, $this->nextReturning($response));

        $this->assertSame($response, $result);
    }

    /** @test */
    public function it_passes_request_through_when_http_tracking_is_disabled(): void
    {
        config(['zenith.http.enabled' => false]);

        $this->transport->shouldReceive('publish')->never();

        $request = $this->makeRequest();
        $response = $this->makeResponse(200);
        $middleware = $this->makeMiddleware();

        $result = $middleware->handle($request, $this->nextReturning($response));

        $this->assertSame($response, $result);
    }

    /** @test */
    public function it_skips_ignored_paths(): void
    {
        config(['zenith.http.ignore_paths' => ['/nova*']]);

        $this->transport->shouldReceive('publish')->never();

        $request = $this->makeRequest('nova/resources/users');
        $response = $this->makeResponse(200);
        $middleware = $this->makeMiddleware();

        $result = $middleware->handle($request, $this->nextReturning($response));

        $this->assertSame($response, $result);
    }

    /** @test */
    public function it_does_not_log_normal_fast_requests_below_slow_threshold(): void
    {
        config(['zenith.http.slow_threshold' => 1000]);

        $this->transport->shouldReceive('publish')->never();
        $this->transport->shouldReceive('increment')->never();

        $request = $this->makeRequest('api/users');
        $response = $this->makeResponse(200);
        $middleware = $this->makeMiddleware();

        $middleware->handle($request, $this->nextReturning($response));

        $this->assertTrue(true);
    }

    /** @test */
    public function it_logs_slow_requests_above_slow_threshold(): void
    {
        config(['zenith.http.slow_threshold' => 0]); // every request is "slow"

        $publishedPayload = null;

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(function (array $payload) use (&$publishedPayload) {
                    $publishedPayload = $payload;
                    return true;
                })
            );

        $this->transport->shouldReceive('increment')->twice();

        $request = $this->makeRequest('api/users', 'GET');
        $response = $this->makeResponse(200);
        $middleware = $this->makeMiddleware();

        $middleware->handle($request, $this->nextReturning($response));

        $this->assertNotNull($publishedPayload);
        $this->assertSame('warn', $publishedPayload['level']);
        $this->assertStringContainsString('Slow Request', $publishedPayload['message']);
        $this->assertSame('GET', $publishedPayload['context']['method']);
        $this->assertSame(200, $publishedPayload['context']['status']);
    }

    /** @test */
    public function it_logs_5xx_error_responses(): void
    {
        $publishedPayload = null;

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(function (array $payload) use (&$publishedPayload) {
                    $publishedPayload = $payload;
                    return true;
                })
            );

        $this->transport->shouldReceive('increment')->once();

        $request = $this->makeRequest('api/endpoint');
        $response = $this->makeResponse(500);
        $middleware = $this->makeMiddleware();

        $middleware->handle($request, $this->nextReturning($response));

        $this->assertNotNull($publishedPayload);
        $this->assertSame('error', $publishedPayload['level']);
        $this->assertSame(500, $publishedPayload['context']['status']);
        $this->assertStringContainsString('HTTP 500', $publishedPayload['message']);
    }

    /** @test */
    public function it_logs_4xx_client_error_responses(): void
    {
        $publishedPayload = null;

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(function (array $payload) use (&$publishedPayload) {
                    $publishedPayload = $payload;
                    return true;
                })
            );

        $this->transport->shouldReceive('increment')->once();

        $request = $this->makeRequest('api/missing');
        $response = $this->makeResponse(404);
        $middleware = $this->makeMiddleware();

        $middleware->handle($request, $this->nextReturning($response));

        $this->assertNotNull($publishedPayload);
        $this->assertSame('warn', $publishedPayload['level']);
        $this->assertSame(404, $publishedPayload['context']['status']);
        $this->assertStringContainsString('HTTP 404', $publishedPayload['message']);
    }

    /** @test */
    public function determine_level_returns_correct_levels(): void
    {
        $middleware = $this->makeMiddleware();
        $method = new \ReflectionMethod($middleware, 'determineLevel');
        $method->setAccessible(true);

        $this->assertSame('error', $method->invoke($middleware, 500, 10));
        $this->assertSame('warn', $method->invoke($middleware, 404, 10));
        $this->assertSame('warn', $method->invoke($middleware, 200, 1001));
        $this->assertSame('info', $method->invoke($middleware, 200, 100));
    }

    /** @test */
    public function published_payload_contains_all_required_fields(): void
    {
        config(['zenith.http.slow_threshold' => 0]); // force publish

        $publishedPayload = null;

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(function (array $payload) use (&$publishedPayload) {
                    $publishedPayload = $payload;
                    return true;
                })
            );

        $this->transport->shouldReceive('increment')->twice();

        $request = $this->makeRequest('api/orders', 'POST');
        $response = $this->makeResponse(200);
        $middleware = $this->makeMiddleware();

        $middleware->handle($request, $this->nextReturning($response));

        $this->assertArrayHasKey('level', $publishedPayload);
        $this->assertArrayHasKey('message', $publishedPayload);
        $this->assertArrayHasKey('workerId', $publishedPayload);
        $this->assertArrayHasKey('timestamp', $publishedPayload);
        $this->assertArrayHasKey('context', $publishedPayload);

        $context = $publishedPayload['context'];
        $this->assertArrayHasKey('method', $context);
        $this->assertArrayHasKey('path', $context);
        $this->assertArrayHasKey('status', $context);
        $this->assertArrayHasKey('duration', $context);
        $this->assertArrayHasKey('route', $context);

        $this->assertSame('POST', $context['method']);
        $this->assertStringEndsWith('-http', $publishedPayload['workerId']);
    }

    /** @test */
    public function it_uses_custom_channel_names(): void
    {
        config(['zenith.http.slow_threshold' => 0]);

        $customChannels = new ChannelRegistry([
            'logs' => 'custom:logs',
            'http' => 'custom:http:',
        ]);
        $middleware = new RecordRequestMetrics($this->transport, $customChannels);

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with('custom:logs', Mockery::any());

        $this->transport
            ->shouldReceive('increment')
            ->with(Mockery::on(fn (string $k) => str_starts_with($k, 'custom:http:')), Mockery::any());
        $this->transport
            ->shouldReceive('increment')
            ->with(Mockery::on(fn (string $k) => str_starts_with($k, 'custom:http:slow:')), Mockery::any());

        $middleware->handle(
            $this->makeRequest('api/test'),
            $this->nextReturning($this->makeResponse(200))
        );

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function should_ignore_path_normalises_leading_slash(): void
    {
        config(['zenith.http.ignore_paths' => ['/health']]);

        $middleware = $this->makeMiddleware();
        $method = new \ReflectionMethod($middleware, 'shouldIgnorePath');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($middleware, 'health'));
        $this->assertTrue($method->invoke($middleware, '/health'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/phpunit tests/Unit/RecordRequestMetricsTest.php -v`
Expected: FAIL — `RecordRequestMetrics` constructor doesn't accept `TransportInterface`/`ChannelRegistry`

- [ ] **Step 3: Update RecordRequestMetrics implementation**

Replace `src/Http/Middleware/RecordRequestMetrics.php` with:

```php
<?php

namespace Gravito\Zenith\Laravel\Http\Middleware;

use Closure;
use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Gravito\Zenith\Laravel\DataTransferObjects\LogEntry;
use Gravito\Zenith\Laravel\Support\ChannelRegistry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordRequestMetrics
{
    protected array $ignorePaths;
    protected int $slowThreshold;

    public function __construct(
        protected TransportInterface $transport,
        protected ChannelRegistry $channels,
    ) {
        $this->ignorePaths = config('zenith.http.ignore_paths', []);
        $this->slowThreshold = config('zenith.http.slow_threshold', 1000);
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!config('zenith.enabled', true) || !config('zenith.http.enabled', true)) {
            return $next($request);
        }

        if ($this->shouldIgnorePath($request->path())) {
            return $next($request);
        }

        $startTime = microtime(true);

        $response = $next($request);

        $duration = (microtime(true) - $startTime) * 1000;

        $this->recordMetrics($request, $response, $duration);

        return $response;
    }

    protected function recordMetrics(Request $request, Response $response, float $duration): void
    {
        $statusCode = $response->getStatusCode();
        $route = $request->route();
        $routeName = 'Unknown';
        if ($route instanceof \Illuminate\Routing\Route) {
            $routeName = $route->getName() ?? $route->getActionName();
        }

        $level = $this->determineLevel($statusCode, $duration);

        if ($level === 'info' && $duration < $this->slowThreshold) {
            return;
        }

        $message = $this->formatMessage($request, $statusCode, $duration, $routeName);

        $entry = new LogEntry(
            level: $level,
            message: $message,
            workerId: gethostname() . '-http',
            timestamp: now()->toIso8601String(),
            context: [
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => $statusCode,
                'duration' => round($duration, 2),
                'route' => $routeName,
            ],
        );

        $this->transport->publish($this->channels->logs(), $entry->toArray());

        $this->incrementMetrics($statusCode, $duration);
    }

    protected function determineLevel(int $statusCode, float $duration): string
    {
        if ($statusCode >= 500) {
            return 'error';
        }

        if ($statusCode >= 400) {
            return 'warn';
        }

        if ($duration >= $this->slowThreshold) {
            return 'warn';
        }

        return 'info';
    }

    protected function formatMessage(Request $request, int $statusCode, float $duration, string $routeName): string
    {
        $method = $request->method();
        $path = $request->path();
        $durationFormatted = round($duration, 2) . 'ms';

        if ($statusCode >= 400) {
            return "HTTP {$statusCode} {$method} /{$path} ({$durationFormatted})";
        }

        if ($duration >= $this->slowThreshold) {
            return "Slow Request: {$method} /{$path} ({$durationFormatted})";
        }

        return "HTTP {$statusCode} {$method} /{$path} ({$durationFormatted})";
    }

    protected function incrementMetrics(int $statusCode, float $duration): void
    {
        $minute = (int) floor(time() / 60);
        $ttl = $this->channels->counterTtl();

        $statusCategory = $this->getStatusCategory($statusCode);
        $this->transport->increment(
            $this->channels->httpMetricKey($statusCategory, $minute),
            $ttl,
        );

        if ($duration >= $this->slowThreshold) {
            $this->transport->increment(
                $this->channels->httpMetricKey('slow', $minute),
                $ttl,
            );
        }
    }

    protected function getStatusCategory(int $statusCode): string
    {
        return substr((string) $statusCode, 0, 1) . 'xx';
    }

    protected function shouldIgnorePath(string $path): bool
    {
        $path = '/' . ltrim($path, '/');

        foreach ($this->ignorePaths as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/RecordRequestMetricsTest.php -v`
Expected: All tests PASS

- [ ] **Step 5: Commit**

```bash
git add src/Http/Middleware/RecordRequestMetrics.php tests/Unit/RecordRequestMetricsTest.php
git commit -m "refactor: [http] RecordRequestMetrics 改用 TransportInterface + ChannelRegistry + LogEntry"
```

---

### Task 9: Update Artisan Commands

**Files:**
- Modify: `src/Console/ZenithHeartbeatCommand.php`
- Modify: `src/Console/ZenithCheckCommand.php`
- Modify: `tests/Feature/ZenithCheckCommandTest.php`

- [ ] **Step 1: Update ZenithHeartbeatCommand**

Replace `src/Console/ZenithHeartbeatCommand.php` with:

```php
<?php

namespace Gravito\Zenith\Laravel\Console;

use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Gravito\Zenith\Laravel\DataTransferObjects\HeartbeatEntry;
use Gravito\Zenith\Laravel\Support\ChannelRegistry;
use Gravito\Zenith\Laravel\Support\GeneratesWorkerId;
use Illuminate\Console\Command;

class ZenithHeartbeatCommand extends Command
{
    use GeneratesWorkerId;

    protected $signature = 'zenith:heartbeat';
    protected $description = 'Send periodic heartbeat to Zenith (run as daemon)';

    protected string $workerId;
    protected int $startTime;

    public function handle(TransportInterface $transport, ChannelRegistry $channels): int
    {
        $this->workerId = $this->generateWorkerId();
        $this->startTime = time();

        $interval = config('zenith.heartbeat.interval', 5);
        $ttl = config('zenith.heartbeat.ttl', 30);

        $this->info("Starting Zenith Heartbeat (Worker ID: {$this->workerId})");
        $this->info("Interval: {$interval}s, TTL: {$ttl}s");
        $this->newLine();

        while (true) { // @phpstan-ignore while.alwaysTrue
            $this->sendHeartbeat($transport, $channels, $ttl);
            sleep($interval);
        }
    }

    protected function sendHeartbeat(TransportInterface $transport, ChannelRegistry $channels, int $ttl): void
    {
        $entry = new HeartbeatEntry(
            id: $this->workerId,
            hostname: gethostname(),
            pid: getmypid(),
            uptime: time() - $this->startTime,
            queues: $this->getMonitoredQueues(),
            concurrency: $this->getConcurrency(),
            memoryUsedMb: round(memory_get_usage() / 1024 / 1024, 2),
            memoryPeakMb: round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            timestamp: now()->toIso8601String(),
            loadAvg: $this->getLoadAverage(),
        );

        $transport->store(
            $channels->workerKey($this->workerId),
            $entry->toArray(),
            $ttl,
        );

        $this->line("heartbeat sent at " . now()->format('H:i:s'));
    }

    protected function getMonitoredQueues(): array
    {
        $queueConnection = config('queue.default');
        $queues = config("queue.connections.{$queueConnection}.queue");

        if (is_string($queues)) {
            return [$queues];
        }

        if (is_array($queues)) {
            return $queues;
        }

        return ['default'];
    }

    protected function getConcurrency(): int
    {
        if (config('horizon')) {
            $environments = config('horizon.environments', []);
            $environment = config('app.env', 'production');

            if (isset($environments[$environment]) && is_array($environments[$environment])) {
                $supervisors = $environments[$environment];
                $supervisor = reset($supervisors);

                if (is_array($supervisor) && isset($supervisor['processes'])) {
                    $value = (int) $supervisor['processes'];
                    return $value > 0 ? $value : 1;
                }
            }
        }

        return 1;
    }

    protected function getLoadAverage(): array
    {
        if (function_exists('sys_getloadavg')) {
            return sys_getloadavg();
        }

        return [0, 0, 0];
    }
}
```

- [ ] **Step 2: Update ZenithCheckCommand**

Replace `src/Console/ZenithCheckCommand.php` with:

```php
<?php

namespace Gravito\Zenith\Laravel\Console;

use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Gravito\Zenith\Laravel\DataTransferObjects\LogEntry;
use Gravito\Zenith\Laravel\Support\ChannelRegistry;
use Illuminate\Console\Command;

class ZenithCheckCommand extends Command
{
    protected $signature = 'zenith:check';
    protected $description = 'Verify Zenith configuration and transport connection';

    public function handle(TransportInterface $transport, ChannelRegistry $channels): int
    {
        $this->info('Zenith Configuration Check');
        $this->newLine();

        $enabled = config('zenith.enabled', false);
        $this->checkItem('Zenith Enabled', $enabled);

        if (!$enabled) {
            $this->warn('Zenith is disabled. Set ZENITH_ENABLED=true in your .env file.');
            return self::FAILURE;
        }

        $connection = config('zenith.connection', 'default');
        $this->info("Redis Connection: <comment>{$connection}</comment>");

        $pingSuccess = $transport->ping();
        $this->checkItem('Transport Connection', $pingSuccess);

        if (!$pingSuccess) {
            $this->error('Failed to connect to transport. Check your configuration.');
            return self::FAILURE;
        }

        $this->info('Testing publish capability...');
        try {
            $entry = new LogEntry(
                level: 'info',
                message: 'Zenith health check',
                workerId: gethostname() . '-check',
                timestamp: now()->toIso8601String(),
            );
            $transport->publish($channels->logs(), $entry->toArray());
            $this->checkItem('Publish Test', true);
        } catch (\Throwable $e) {
            $this->checkItem('Publish Test', false);
            $this->error("Publish failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Configuration:');
        $this->table(
            ['Feature', 'Status'],
            [
                ['Logging', config('zenith.logging.enabled') ? 'Enabled' : 'Disabled'],
                ['Queue Monitoring', config('zenith.queues.enabled') ? 'Enabled' : 'Disabled'],
                ['HTTP Monitoring', config('zenith.http.enabled') ? 'Enabled' : 'Disabled'],
            ]
        );

        $this->newLine();
        $this->info('All checks passed! Zenith is ready.');

        return self::SUCCESS;
    }

    protected function checkItem(string $label, bool $success): void
    {
        $icon = $success ? 'OK' : 'FAIL';
        $color = $success ? 'info' : 'error';
        $this->line("  [{$icon}] {$label}", $color);
    }
}
```

- [ ] **Step 3: Run the check command tests**

Run: `./vendor/bin/phpunit tests/Feature/ZenithCheckCommandTest.php -v`
Expected: All tests PASS (Laravel auto-injects `TransportInterface` and `ChannelRegistry` from container)

- [ ] **Step 4: Commit**

```bash
git add src/Console/ZenithHeartbeatCommand.php src/Console/ZenithCheckCommand.php
git commit -m "refactor: [commands] Artisan commands 改用 TransportInterface + ChannelRegistry + DTO"
```

---

### Task 10: Delete legacy files and run full test suite

**Files:**
- Delete: `src/Support/RedisPublisher.php`
- Delete: `src/Support/RedisChannels.php`

- [ ] **Step 1: Delete the legacy files**

```bash
rm src/Support/RedisPublisher.php src/Support/RedisChannels.php
```

- [ ] **Step 2: Run PHPStan on the entire src/ directory**

Run: `./vendor/bin/phpstan analyse src/ --level=5`
Expected: 0 errors (no remaining references to `RedisPublisher` or `RedisChannels`)

- [ ] **Step 3: Run the full test suite**

Run: `./vendor/bin/phpunit -v`
Expected: All tests PASS

- [ ] **Step 4: Commit**

```bash
git add -u src/Support/RedisPublisher.php src/Support/RedisChannels.php
git commit -m "refactor: [cleanup] 移除已棄用的 RedisPublisher 與 RedisChannels"
```

---

### Task 11: Update CLAUDE.md

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Update the Architecture section in CLAUDE.md**

Replace the Core Components and Shared Infrastructure sections to reflect the new architecture:

- `RedisPublisher` → `RedisTransport implements TransportInterface`
- `RedisChannels` → `ChannelRegistry`
- Document the new `DataTransferObjects/` directory
- Document the new `Contracts/` directory
- Update the Redis Key Convention section to note that all keys are now configurable via `config('zenith.channels')`

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: [zenith] 更新 CLAUDE.md 反映 Transport 抽象層架構"
```
