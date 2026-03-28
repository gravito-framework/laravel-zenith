# Pluggable Transport Layer 實作計畫

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 將 Zenith 的 Transport 層從硬編碼 Redis 改為可插拔的 Manager Pattern 架構。

**Architecture:** 新增 `TransportManager`（繼承 `Illuminate\Support\Manager`）作為 driver 解析器，內建 Redis + Null 兩個 driver。`TransportInterface` 的參數改為通用語意。ServiceProvider 改用 TransportManager 綁定，config 結構重組。

**Tech Stack:** PHP 8.1+, Laravel 10-11, Orchestra Testbench, PHPUnit, Mockery

**Spec:** `docs/superpowers/specs/2026-03-28-pluggable-transport-design.md`

---

## 檔案結構

### 新增

| 檔案 | 職責 |
|------|------|
| `src/Transport/TransportManager.php` | Manager Pattern 核心，解析 driver |
| `src/Transport/NullTransport.php` | 空實作，用於停用/測試 |
| `tests/Unit/TransportManagerTest.php` | TransportManager 單元測試 |
| `tests/Unit/NullTransportTest.php` | NullTransport 單元測試 |

### 修改

| 檔案 | 變更 |
|------|------|
| `src/Contracts/TransportInterface.php` | `$channel` → `$topic`，`mixed $value` → `array $data` |
| `src/Transport/RedisTransport.php` | 移除 enabled 檢查、參數對齊、建構子簡化 |
| `src/ZenithServiceProvider.php` | 改用 TransportManager 綁定 |
| `config/zenith.php` | `connection` 移入 `transport` 群組 |
| `src/Support/ConfigValidator.php` | 新增 transport 驗證 |
| `tests/TestCase.php` | config 設定對齊新結構 |
| `tests/Unit/RedisTransportTest.php` | 移除 enabled 測試、參數對齊 |
| `tests/Unit/ConfigValidatorTest.php` | 新增 transport 驗證測試 |

---

## Task 1: TransportInterface 參數通用化

**Files:**
- Modify: `src/Contracts/TransportInterface.php`

- [ ] **Step 1: 更新 TransportInterface**

將 `publish` 的 `$channel` 改為 `$topic`，`store` 的 `mixed $value` 改為 `array $data`：

```php
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
```

- [ ] **Step 2: 執行測試確認破壞範圍**

Run: `./vendor/bin/phpunit --testsuite=Unit 2>&1 | tail -20`

Expected: 測試仍然通過（因為 PHP 介面參數名不影響既有的位置參數呼叫）。如果有 test 使用 named arguments 則會失敗，後續 task 修正。

- [ ] **Step 3: Commit**

```bash
git add src/Contracts/TransportInterface.php
git commit -m "refactor: [transport] TransportInterface 參數通用化 ($channel→$topic, mixed→array)"
```

---

## Task 2: NullTransport 實作

**Files:**
- Create: `src/Transport/NullTransport.php`
- Create: `tests/Unit/NullTransportTest.php`

- [ ] **Step 1: 寫 NullTransport 的失敗測試**

```php
<?php

namespace Gravito\Zenith\Laravel\Tests\Unit;

use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Gravito\Zenith\Laravel\Transport\NullTransport;
use Gravito\Zenith\Laravel\Tests\TestCase;

class NullTransportTest extends TestCase
{
    protected NullTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new NullTransport();
    }

    /** @test */
    public function it_implements_transport_interface(): void
    {
        $this->assertInstanceOf(TransportInterface::class, $this->transport);
    }

    /** @test */
    public function publish_does_nothing_without_error(): void
    {
        $this->transport->publish('test-topic', ['key' => 'value']);
        $this->assertTrue(true);
    }

    /** @test */
    public function store_does_nothing_without_error(): void
    {
        $this->transport->store('test-key', ['data' => 'value'], 60);
        $this->assertTrue(true);
    }

    /** @test */
    public function increment_does_nothing_without_error(): void
    {
        $this->transport->increment('test-counter', 3600);
        $this->assertTrue(true);
    }

    /** @test */
    public function ping_returns_true(): void
    {
        $this->assertTrue($this->transport->ping());
    }
}
```

- [ ] **Step 2: 執行測試確認失敗**

Run: `./vendor/bin/phpunit tests/Unit/NullTransportTest.php 2>&1 | tail -10`

Expected: FAIL — `Class "Gravito\Zenith\Laravel\Transport\NullTransport" not found`

- [ ] **Step 3: 實作 NullTransport**

```php
<?php

namespace Gravito\Zenith\Laravel\Transport;

use Gravito\Zenith\Laravel\Contracts\TransportInterface;

class NullTransport implements TransportInterface
{
    public function publish(string $topic, array $payload): void
    {
    }

    public function store(string $key, array $data, int $ttl): void
    {
    }

    public function increment(string $key, ?int $ttl = null): void
    {
    }

    public function ping(): bool
    {
        return true;
    }
}
```

- [ ] **Step 4: 執行測試確認通過**

Run: `./vendor/bin/phpunit tests/Unit/NullTransportTest.php 2>&1 | tail -10`

Expected: OK (5 tests, 5 assertions)

- [ ] **Step 5: Commit**

```bash
git add src/Transport/NullTransport.php tests/Unit/NullTransportTest.php
git commit -m "feat: [transport] 新增 NullTransport 實作"
```

---

## Task 3: RedisTransport 改造

**Files:**
- Modify: `src/Transport/RedisTransport.php`
- Modify: `tests/Unit/RedisTransportTest.php`

- [ ] **Step 1: 更新 RedisTransportTest — 移除 enabled 測試、參數對齊**

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
        $this->transport = new RedisTransport('default');
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
                return $args[0] === 'test-topic' && is_string($args[1]);
            }))
            ->andReturn(1);

        Redis::shouldReceive('connection')
            ->once()
            ->with('default')
            ->andReturn($connection);

        $this->transport->publish('test-topic', ['message' => 'test']);
    }

    /** @test */
    public function it_can_store_array_data_with_ttl(): void
    {
        $connection = \Mockery::mock(\Illuminate\Redis\Connections\Connection::class);
        $connection->shouldReceive('command')
            ->once()
            ->with('setex', \Mockery::on(function ($args) {
                return $args[0] === 'test-key'
                    && $args[1] === 60
                    && json_decode($args[2], true) === ['data' => 'value'];
            }))
            ->andReturn('OK');

        Redis::shouldReceive('connection')
            ->once()
            ->with('default')
            ->andReturn($connection);

        $this->transport->store('test-key', ['data' => 'value'], 60);
    }

    /** @test */
    public function it_can_increment_counters(): void
    {
        $connection = \Mockery::mock(\Illuminate\Redis\Connections\Connection::class);
        $connection->shouldReceive('command')
            ->once()
            ->with('incr', ['test-counter'])
            ->andReturn(1);

        $connection->shouldReceive('command')
            ->once()
            ->with('expire', ['test-counter', 3600])
            ->andReturn(1);

        Redis::shouldReceive('connection')
            ->with('default')
            ->andReturn($connection);

        $this->transport->increment('test-counter', 3600);
    }

    /** @test */
    public function it_silently_fails_on_redis_errors(): void
    {
        Redis::shouldReceive('connection')
            ->once()
            ->andThrow(new \Exception('Redis connection failed'));

        $this->transport->publish('test-topic', ['message' => 'test']);

        $this->assertTrue(true);
    }
}
```

**移除的測試：** `it_does_not_publish_when_zenith_is_disabled` — 停用邏輯改由 TransportManager 負責。
**變更：** 建構子改為必傳 `string $connection`，`test_channel` → `test-topic`，store 測試改用 `array` 參數。

- [ ] **Step 2: 執行測試確認失敗**

Run: `./vendor/bin/phpunit tests/Unit/RedisTransportTest.php 2>&1 | tail -10`

Expected: FAIL — 因為 RedisTransport 建構子尚未更新。

- [ ] **Step 3: 更新 RedisTransport**

```php
<?php

namespace Gravito\Zenith\Laravel\Transport;

use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Throwable;

class RedisTransport implements TransportInterface
{
    public function __construct(
        private readonly string $connection
    ) {
    }

    public function publish(string $topic, array $payload): void
    {
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            $this->redis()->command('publish', [$topic, $json]);
        } catch (Throwable) {
            // Silently fail to avoid disrupting the application
        }
    }

    public function store(string $key, array $data, int $ttl): void
    {
        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR);
            $this->redis()->command('setex', [$key, $ttl, $json]);
        } catch (Throwable) {
            // Silently fail
        }
    }

    public function increment(string $key, ?int $ttl = null): void
    {
        try {
            $this->redis()->command('incr', [$key]);

            if ($ttl !== null) {
                $this->redis()->command('expire', [$key, $ttl]);
            }
        } catch (Throwable) {
            // Silently fail
        }
    }

    public function ping(): bool
    {
        try {
            $response = $this->redis()->command('ping');

            return $response === 'PONG' || $response === true;
        } catch (Throwable) {
            return false;
        }
    }

    protected function redis(): Connection
    {
        return Redis::connection($this->connection);
    }
}
```

**移除：** 每個方法的 `config('zenith.enabled')` 檢查、建構子的 nullable + config fallback。
**變更：** `$channel` → `$topic`，`mixed $value` → `array $data`，`store` 內一律 JSON encode（不再判斷型別）。

- [ ] **Step 4: 執行測試確認通過**

Run: `./vendor/bin/phpunit tests/Unit/RedisTransportTest.php 2>&1 | tail -10`

Expected: OK (5 tests, 5 assertions)

- [ ] **Step 5: Commit**

```bash
git add src/Transport/RedisTransport.php tests/Unit/RedisTransportTest.php
git commit -m "refactor: [transport] RedisTransport 移除 enabled 檢查、參數通用化"
```

---

## Task 4: TransportManager 實作

**Files:**
- Create: `src/Transport/TransportManager.php`
- Create: `tests/Unit/TransportManagerTest.php`

- [ ] **Step 1: 寫 TransportManager 的失敗測試**

```php
<?php

namespace Gravito\Zenith\Laravel\Tests\Unit;

use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Gravito\Zenith\Laravel\Transport\NullTransport;
use Gravito\Zenith\Laravel\Transport\RedisTransport;
use Gravito\Zenith\Laravel\Transport\TransportManager;
use Gravito\Zenith\Laravel\Tests\TestCase;

class TransportManagerTest extends TestCase
{
    /** @test */
    public function it_resolves_redis_driver_by_default(): void
    {
        config([
            'zenith.enabled' => true,
            'zenith.transport' => ['driver' => 'redis', 'connection' => 'default'],
        ]);

        $manager = $this->app->make(TransportManager::class);

        $this->assertInstanceOf(RedisTransport::class, $manager->driver());
    }

    /** @test */
    public function it_resolves_null_driver(): void
    {
        config([
            'zenith.enabled' => true,
            'zenith.transport' => ['driver' => 'null'],
        ]);

        $manager = $this->app->make(TransportManager::class);

        $this->assertInstanceOf(NullTransport::class, $manager->driver());
    }

    /** @test */
    public function it_falls_back_to_null_when_disabled(): void
    {
        config([
            'zenith.enabled' => false,
            'zenith.transport' => ['driver' => 'redis', 'connection' => 'default'],
        ]);

        $manager = $this->app->make(TransportManager::class);

        $this->assertInstanceOf(NullTransport::class, $manager->driver());
    }

    /** @test */
    public function it_supports_custom_drivers_via_extend(): void
    {
        config([
            'zenith.enabled' => true,
            'zenith.transport' => ['driver' => 'custom'],
        ]);

        $manager = $this->app->make(TransportManager::class);

        $customTransport = new NullTransport();
        $manager->extend('custom', fn () => $customTransport);

        $this->assertSame($customTransport, $manager->driver());
    }

    /** @test */
    public function driver_returns_transport_interface(): void
    {
        config([
            'zenith.enabled' => true,
            'zenith.transport' => ['driver' => 'redis', 'connection' => 'default'],
        ]);

        $manager = $this->app->make(TransportManager::class);

        $this->assertInstanceOf(TransportInterface::class, $manager->driver());
    }

    /** @test */
    public function it_defaults_to_redis_when_no_transport_config(): void
    {
        config([
            'zenith.enabled' => true,
            'zenith.transport' => null,
        ]);

        $manager = $this->app->make(TransportManager::class);

        $this->assertInstanceOf(RedisTransport::class, $manager->driver());
    }
}
```

- [ ] **Step 2: 執行測試確認失敗**

Run: `./vendor/bin/phpunit tests/Unit/TransportManagerTest.php 2>&1 | tail -10`

Expected: FAIL — `Class "Gravito\Zenith\Laravel\Transport\TransportManager" not found`

- [ ] **Step 3: 實作 TransportManager**

```php
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

        return config('zenith.transport.driver', 'redis');
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
```

- [ ] **Step 4: 執行測試確認通過**

Run: `./vendor/bin/phpunit tests/Unit/TransportManagerTest.php 2>&1 | tail -10`

Expected: OK (6 tests, 6 assertions)

- [ ] **Step 5: Commit**

```bash
git add src/Transport/TransportManager.php tests/Unit/TransportManagerTest.php
git commit -m "feat: [transport] 新增 TransportManager — Manager Pattern 實作"
```

---

## Task 5: Config 結構重組

**Files:**
- Modify: `config/zenith.php`

- [ ] **Step 1: 更新 config/zenith.php**

將頂層 `connection` 移入 `transport` 群組：

```php
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
```

- [ ] **Step 2: 執行測試確認現況**

Run: `./vendor/bin/phpunit --testsuite=Unit 2>&1 | tail -10`

Expected: 可能有部分測試失敗（ServiceProvider 尚未更新），記錄失敗數量。

- [ ] **Step 3: Commit**

```bash
git add config/zenith.php
git commit -m "refactor: [config] connection 移入 transport 群組、新增 driver 設定"
```

---

## Task 6: ServiceProvider 改造

**Files:**
- Modify: `src/ZenithServiceProvider.php`
- Modify: `tests/TestCase.php`

- [ ] **Step 1: 更新 TestCase 的 config 設定**

```php
<?php

namespace Gravito\Zenith\Laravel\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Gravito\Zenith\Laravel\ZenithServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * Get package providers.
     */
    protected function getPackageProviders($app): array
    {
        return [
            ZenithServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     */
    protected function defineEnvironment($app): void
    {
        // Setup default configuration
        $app['config']->set('zenith.enabled', true);
        $app['config']->set('zenith.transport', [
            'driver' => 'redis',
            'connection' => 'default',
        ]);
        $app['config']->set('zenith.logging.enabled', true);
        $app['config']->set('zenith.queues.enabled', true);
        $app['config']->set('zenith.http.enabled', true);

        // Setup Redis connection for testing
        $app['config']->set('database.redis.default', [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null),
            'port' => env('REDIS_PORT', 6379),
            'database' => env('REDIS_DB', 0),
        ]);
    }
}
```

**變更：** `zenith.connection` → `zenith.transport` 陣列。

- [ ] **Step 2: 更新 ZenithServiceProvider**

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
use Gravito\Zenith\Laravel\Transport\TransportManager;
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

        $this->app->singleton(TransportManager::class);

        $this->app->singleton(TransportInterface::class, function ($app) {
            return $app->make(TransportManager::class)->driver();
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

**變更：**
- `use RedisTransport` → `use TransportManager`
- `TransportInterface` 綁定改為從 `TransportManager::driver()` 解析
- `TransportManager` 註冊為 singleton

- [ ] **Step 3: 執行所有單元測試**

Run: `./vendor/bin/phpunit --testsuite=Unit 2>&1 | tail -20`

Expected: All tests pass

- [ ] **Step 4: Commit**

```bash
git add src/ZenithServiceProvider.php tests/TestCase.php
git commit -m "refactor: [provider] ServiceProvider 改用 TransportManager 綁定"
```

---

## Task 7: ConfigValidator 更新

**Files:**
- Modify: `src/Support/ConfigValidator.php`
- Modify: `tests/Unit/ConfigValidatorTest.php`

- [ ] **Step 1: 更新 ConfigValidatorTest — 新增 transport 驗證測試**

在現有測試檔案中新增以下測試方法，並更新 `setValidConfig` 加入 `transport` 欄位：

在 `setValidConfig` 方法的 default config 中加入：

```php
'transport' => [
    'driver' => 'redis',
    'connection' => 'default',
],
```

新增測試方法：

```php
/** @test */
public function non_string_transport_driver_throws_invalid_argument_exception(): void
{
    $this->setValidConfig(['transport' => ['driver' => 123]]);

    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('zenith.transport.driver must be a string.');

    ConfigValidator::validate();
}

/** @test */
public function empty_transport_driver_throws_invalid_argument_exception(): void
{
    $this->setValidConfig(['transport' => ['driver' => '']]);

    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('zenith.transport.driver must be a non-empty string.');

    ConfigValidator::validate();
}

/** @test */
public function valid_config_with_transport_section_passes(): void
{
    $this->setValidConfig([
        'transport' => ['driver' => 'redis', 'connection' => 'default'],
    ]);

    ConfigValidator::validate();

    $this->assertTrue(true);
}
```

- [ ] **Step 2: 執行測試確認失敗**

Run: `./vendor/bin/phpunit tests/Unit/ConfigValidatorTest.php --filter=transport 2>&1 | tail -10`

Expected: FAIL — ConfigValidator 尚未有 transport 驗證邏輯。

- [ ] **Step 3: 更新 ConfigValidator — 新增 validateTransportConfig**

在 `ConfigValidator` 的 `validate()` 方法中新增呼叫：

```php
public static function validate(): void
{
    $config = config('zenith');

    if (!is_array($config)) {
        throw new InvalidArgumentException('Zenith configuration is missing. Run: php artisan vendor:publish --tag=zenith-config');
    }

    self::validateTransportConfig($config['transport'] ?? []);
    self::validateHttpConfig($config['http'] ?? []);
    self::validateHeartbeatConfig($config['heartbeat'] ?? []);
    self::validateQueueConfig($config['queues'] ?? []);
}

protected static function validateTransportConfig(array $transport): void
{
    $driver = $transport['driver'] ?? 'redis';

    if (!is_string($driver)) {
        throw new InvalidArgumentException('zenith.transport.driver must be a string.');
    }

    if ($driver === '') {
        throw new InvalidArgumentException('zenith.transport.driver must be a non-empty string.');
    }
}
```

- [ ] **Step 4: 執行全部 ConfigValidatorTest**

Run: `./vendor/bin/phpunit tests/Unit/ConfigValidatorTest.php 2>&1 | tail -10`

Expected: All tests pass（包含既有的 + 新增的）

- [ ] **Step 5: Commit**

```bash
git add src/Support/ConfigValidator.php tests/Unit/ConfigValidatorTest.php
git commit -m "feat: [config] ConfigValidator 新增 transport.driver 驗證"
```

---

## Task 8: 全套測試驗證 + PHPStan

**Files:** 無新檔案

- [ ] **Step 1: 執行所有單元測試**

Run: `./vendor/bin/phpunit --testsuite=Unit 2>&1`

Expected: All tests pass

- [ ] **Step 2: 執行所有功能測試（如有）**

Run: `./vendor/bin/phpunit --testsuite=Feature 2>&1`

Expected: All tests pass（或 no tests，取決於 Feature suite 是否存在）

- [ ] **Step 3: 執行 PHPStan 靜態分析**

Run: `./vendor/bin/phpstan analyse 2>&1`

Expected: 0 errors（Level 5）。如有 error 修正後重新執行。

- [ ] **Step 4: 修正任何 PHPStan errors**

根據 PHPStan 輸出修正型別問題。常見問題：
- `store()` 的 `mixed $value` → `array $data` 型別不匹配
- TransportManager 的回傳型別

修正後重新執行 Step 3 確認 0 errors。

- [ ] **Step 5: Commit（如有修正）**

```bash
git add -A
git commit -m "fix: [types] 修正 PHPStan 靜態分析錯誤"
```

---

## Task 9: 清理舊設計文件

**Files:**
- Delete: `docs/superpowers/specs/2026-03-28-transport-abstraction-design.md`（舊版設計，已被 pluggable-transport-design.md 取代）

- [ ] **Step 1: 確認舊文件內容已被新設計涵蓋**

舊文件是 v1 Transport 抽象設計（DTO + ChannelRegistry），已在 0.2.0 實作完成。新文件是 v2 pluggable transport 設計。確認無遺漏。

- [ ] **Step 2: 刪除舊設計文件**

```bash
git rm docs/superpowers/specs/2026-03-28-transport-abstraction-design.md
git commit -m "chore: [docs] 移除舊版 transport abstraction 設計文件"
```
