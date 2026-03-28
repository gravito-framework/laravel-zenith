# Pluggable Transport Layer 設計規格

## 目標

將 Laravel Zenith 的 Transport 層從硬編碼 Redis 改為可插拔架構，讓社群可以自行實作不同監控後端（Prometheus、Datadog、InfluxDB 等），同時保持核心精簡。

## 決策記錄

| 問題 | 決定 | 理由 |
|------|------|------|
| 介面策略 | 分層（高階通用 + 低階實作） | 元件面對通用介面，Transport 實作者面對具體操作 |
| 向後相容 | 破壞性變更 | 0.x 版本，趁機一次到位 |
| 開發者體驗 | Config driver + 獨立套件（Manager Pattern） | Laravel 標準模式（Cache、Queue、Mail） |
| 介面語意 | 通用操作語意 | publish/store/increment/ping 不綁 Redis |
| 內建 Transport | Redis + NullTransport | Redis 為主要實作，Null 用於停用/測試 |
| Fan-out | 不支援 | YAGNI，架構預留擴展空間 |

## 設計

### 1. TransportInterface（重新設計）

`src/Contracts/TransportInterface.php`

```php
namespace Gravito\Zenith\Laravel\Contracts;

interface TransportInterface
{
    /**
     * 發布事件到指定主題（pub/sub 語意）
     */
    public function publish(string $topic, array $payload): void;

    /**
     * 儲存帶有 TTL 的狀態資料（key-value 語意）
     */
    public function store(string $key, array $data, int $ttl): void;

    /**
     * 遞增計數器，可選 TTL（counter 語意）
     */
    public function increment(string $key, ?int $ttl = null): void;

    /**
     * 健康檢查
     */
    public function ping(): bool;
}
```

**變更點**：
- `publish` 的 `$channel` → `$topic`（去 Redis 化命名）
- `store` 的 `mixed $value` → `array $data`（統一型別）
- 方法名稱與數量不變，現有元件使用位置參數呼叫，無需修改

### 2. TransportManager

`src/Transport/TransportManager.php`

```php
namespace Gravito\Zenith\Laravel\Transport;

use Illuminate\Support\Manager;
use Gravito\Zenith\Laravel\Contracts\TransportInterface;

class TransportManager extends Manager
{
    public function getDefaultDriver(): string
    {
        if (! config('zenith.enabled')) {
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

**重點**：
- 繼承 `Illuminate\Support\Manager`，自帶 `driver()`、`extend()` 機制
- `enabled = false` 自動降級為 NullTransport，不需要在每個方法裡檢查 config flag
- 社群透過 `extend()` 註冊自訂 driver

**社群擴展範例**：

```php
// 在社群套件的 ServiceProvider 中
$this->app->resolving(TransportManager::class, function ($manager) {
    $manager->extend('datadog', function () {
        return new DatadogTransport(config('zenith.transport.api_key'));
    });
});
```

使用者只需改 config：`'transport.driver' => 'datadog'`

### 3. NullTransport

`src/Transport/NullTransport.php`

```php
namespace Gravito\Zenith\Laravel\Transport;

use Gravito\Zenith\Laravel\Contracts\TransportInterface;

class NullTransport implements TransportInterface
{
    public function publish(string $topic, array $payload): void {}
    public function store(string $key, array $data, int $ttl): void {}
    public function increment(string $key, ?int $ttl = null): void {}
    public function ping(): bool { return true; }
}
```

零邏輯。用途：`zenith.enabled = false` 自動降級、測試環境。

### 4. RedisTransport 調整

`src/Transport/RedisTransport.php` 修改：

- 移除每個方法裡的 `config('zenith.enabled')` 檢查（由 TransportManager 處理）
- `publish` 參數 `$channel` → `$topic`（內部仍用 Redis PUBLISH 指令）
- `store` 參數 `mixed $value` → `array $data`（內部一律 JSON encode）
- 建構子只接收 `string $connection`，不再自己讀 config
- 保持 try/catch 靜默失敗（fire-and-forget 哲學）

```php
class RedisTransport implements TransportInterface
{
    public function __construct(
        private readonly string $connection
    ) {}

    public function publish(string $topic, array $payload): void
    {
        try {
            $this->redis()->command('publish', [
                $topic,
                json_encode($payload, JSON_THROW_ON_ERROR),
            ]);
        } catch (\Throwable) {
            // fail-silent
        }
    }

    // store, increment, ping 同樣模式
}
```

### 5. ServiceProvider 改造

`src/ZenithServiceProvider.php`

```php
public function register(): void
{
    $this->mergeConfigFrom(__DIR__.'/../config/zenith.php', 'zenith');

    $this->app->singleton(TransportManager::class);

    $this->app->singleton(TransportInterface::class, function ($app) {
        return $app->make(TransportManager::class)->driver();
    });

    $this->app->singleton(ChannelRegistry::class, function () {
        return new ChannelRegistry(config('zenith.channels', []));
    });
}
```

所有元件繼續依賴注入 `TransportInterface`，完全不知道 Manager 的存在。

### 6. Config 結構變更

`config/zenith.php`

```php
return [
    'enabled' => env('ZENITH_ENABLED', true),

    // 舊：'connection' => env('ZENITH_REDIS_CONNECTION', 'default'),
    // 新：
    'transport' => [
        'driver' => env('ZENITH_TRANSPORT', 'redis'),
        'connection' => env('ZENITH_REDIS_CONNECTION', 'default'),
    ],

    'channels' => [ /* 不變 */ ],
    'logging'  => [ /* 不變 */ ],
    'queues'   => [ /* 不變 */ ],
    'http'     => [ /* 不變 */ ],
    'heartbeat' => [ /* 不變 */ ],
];
```

`connection` 從頂層移入 `transport` 群組。

### 7. ConfigValidator 調整

- 新增 `transport.driver` 驗證（必須是 string）
- 移除舊的頂層 `connection` 驗證路徑
- 其餘驗證規則不變

## 檔案變更清單

### 新增

| 檔案 | 說明 |
|------|------|
| `src/Transport/TransportManager.php` | Manager Pattern 核心 |
| `src/Transport/NullTransport.php` | 空實作 |
| `tests/Unit/TransportManagerTest.php` | Manager 解析邏輯測試 |
| `tests/Unit/NullTransportTest.php` | NullTransport 行為測試 |

### 修改

| 檔案 | 變更 |
|------|------|
| `src/Contracts/TransportInterface.php` | 參數重新命名/型別調整 |
| `src/Transport/RedisTransport.php` | 移除 enabled 檢查、參數對齊 |
| `src/ZenithServiceProvider.php` | 改用 TransportManager 綁定 |
| `config/zenith.php` | `connection` 移入 `transport` 群組 |
| `src/Support/ConfigValidator.php` | 驗證新 config 結構 |
| `tests/Unit/RedisTransportTest.php` | 參數名稱/型別對齊 |
| `tests/Unit/ConfigValidatorTest.php` | 對應新 config 結構 |

### 不需修改

以下元件使用位置參數呼叫 `TransportInterface`，介面方法名和數量不變，零改動：
- `src/Logging/ZenithLogHandler.php`
- `src/Queue/ZenithQueueSubscriber.php`
- `src/Http/Middleware/RecordRequestMetrics.php`
- `src/Console/ZenithCheckCommand.php`
- `src/Console/ZenithHeartbeatCommand.php`

## 測試策略

1. **TransportManagerTest** — driver 解析、enabled=false 降級為 null、`extend()` 自訂 driver
2. **NullTransportTest** — 所有方法不拋例外、ping 回傳 true
3. **RedisTransportTest** — 更新既有測試，移除 enabled 相關 case，參數型別對齊
4. **ConfigValidatorTest** — 更新既有測試，驗證新 `transport` config 結構
5. **ServiceProvider 整合測試** — 驗證 `TransportInterface` 解析出正確的 driver

## 社群 Transport 開發指南

實作一個自訂 Transport 需要：

1. 建立一個 class 實作 `TransportInterface` 的 4 個方法
2. 在套件的 ServiceProvider 中透過 `TransportManager::extend()` 註冊
3. 使用者在 `config/zenith.php` 設定 `'transport.driver' => 'your-driver'`

```php
// 1. 實作介面
class DatadogTransport implements TransportInterface
{
    public function publish(string $topic, array $payload): void { /* ... */ }
    public function store(string $key, array $data, int $ttl): void { /* ... */ }
    public function increment(string $key, ?int $ttl = null): void { /* ... */ }
    public function ping(): bool { /* ... */ }
}

// 2. 註冊 driver
class DatadogZenithServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->resolving(TransportManager::class, function ($manager) {
            $manager->extend('datadog', fn () => new DatadogTransport(
                config('zenith.transport.api_key')
            ));
        });
    }
}
```
