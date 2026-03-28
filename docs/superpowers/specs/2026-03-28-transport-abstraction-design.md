# Transport 抽象層與資料介面通用化設計

## 目標

將 Laravel Zenith 的輸出資料介面從特定遠端應用（flux_console）解耦，使其成為通用的監控事件發布套件。具體而言：

1. 抽象化傳輸層，不綁死 Redis pub/sub
2. 以 DTO 定義正式的資料契約，欄位語言無關
3. 所有 channel/key 名稱可透過 config 獨立配置

## 設計

### 1. TransportInterface

新增 `src/Contracts/TransportInterface.php`：

```php
namespace Gravito\Zenith\Laravel\Contracts;

interface TransportInterface
{
    /** 發布訊息到指定 channel（pub/sub 語意） */
    public function publish(string $channel, array $payload): void;

    /** 儲存帶有 TTL 的資料（key-value 語意） */
    public function store(string $key, mixed $value, int $ttl): void;

    /** 遞增計數器 */
    public function increment(string $key, ?int $ttl = null): void;

    /** 測試連線是否正常 */
    public function ping(): bool;
}
```

現有 `RedisPublisher` 重構為 `src/Transport/RedisTransport.php`，實作 `TransportInterface`，內部邏輯不變。

### 2. Payload DTO

新增 `src/DataTransferObjects/` 目錄，三個 DTO 類別：

#### LogEntry

```php
namespace Gravito\Zenith\Laravel\DataTransferObjects;

final class LogEntry
{
    public function __construct(
        public readonly string $level,      // 'error' | 'warn' | 'info' | 'success'
        public readonly string $message,
        public readonly string $workerId,
        public readonly string $timestamp,  // ISO 8601
        public readonly array $context = [],
    ) {}

    public function toArray(): array;
}
```

#### HeartbeatEntry

```php
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
        public readonly float $memoryUsedMb,   // 取代 heapUsed
        public readonly float $memoryPeakMb,   // 取代 heapTotal
        public readonly string $timestamp,
        public readonly array $loadAvg,
    ) {}

    public function toArray(): array;
}
```

欄位變更：
- `heapUsed` → `memoryUsedMb`（`memory_get_usage()` 轉 MB）
- `heapTotal` → `memoryPeakMb`（`memory_get_peak_usage()` 取代無意義的 `'N/A'`）
- `rss` 欄位移除（`memory_get_usage(true)` 合併至上述欄位）
- `memory` 巢狀結構扁平化為頂層欄位

#### MetricEntry

```php
namespace Gravito\Zenith\Laravel\DataTransferObjects;

final class MetricEntry
{
    public function __construct(
        public readonly string $name,       // e.g. 'http_2xx', 'http_slow', 'job_throughput'
        public readonly int $window,        // 分鐘級 timestamp (floor(time()/60))
        public readonly int $ttl,
    ) {}

    public function toKey(string $prefix): string;
}
```

### 3. ChannelRegistry

取代 `RedisChannels` 常數類別，改為從 config 讀取：

```php
namespace Gravito\Zenith\Laravel\Support;

final class ChannelRegistry
{
    public function __construct(private readonly array $config) {}

    public function logs(): string;
    public function workerKey(string $workerId): string;
    public function throughputKey(int $window): string;
    public function httpMetricKey(string $category, int $window): string;
    public function counterTtl(): int;
}
```

Config 新增 `channels` 區塊：

```php
'channels' => [
    'logs'        => env('ZENITH_CHANNEL_LOGS', 'zenith:logs'),
    'worker'      => env('ZENITH_CHANNEL_WORKER', 'zenith:worker:'),
    'throughput'  => env('ZENITH_CHANNEL_THROUGHPUT', 'zenith:throughput:'),
    'http'        => env('ZENITH_CHANNEL_HTTP', 'zenith:metrics:http:'),
    'counter_ttl' => 3600,
],
```

預設值從 `flux_console:` 改為 `zenith:`（語意中立）。

### 4. 依賴注入

ServiceProvider 綁定：

```php
$this->app->singleton(TransportInterface::class, fn ($app) =>
    new RedisTransport(config('zenith.connection', 'default'))
);

$this->app->singleton(ChannelRegistry::class, fn ($app) =>
    new ChannelRegistry(config('zenith.channels', []))
);
```

所有元件改為建構子注入 `TransportInterface` + `ChannelRegistry`：

| 元件 | 注入 | 使用的 DTO |
|------|------|-----------|
| ZenithQueueSubscriber | Transport, ChannelRegistry | LogEntry, MetricEntry |
| ZenithLogHandler | Transport, ChannelRegistry | LogEntry |
| RecordRequestMetrics | Transport, ChannelRegistry | LogEntry, MetricEntry |
| ZenithHeartbeatCommand | Transport, ChannelRegistry | HeartbeatEntry |
| ZenithCheckCommand | Transport, ChannelRegistry | — |

Monolog Handler 特殊處理（透過 log driver factory 從 container 取得依賴）：

```php
$this->app->make('log')->extend('zenith', fn ($app, array $config) =>
    new Logger('zenith', [
        new ZenithLogHandler(
            $app->make(TransportInterface::class),
            $app->make(ChannelRegistry::class),
            $config['level'] ?? 'debug',
        ),
    ])
);
```

## 檔案變更

### 新增

- `src/Contracts/TransportInterface.php`
- `src/Transport/RedisTransport.php`
- `src/DataTransferObjects/LogEntry.php`
- `src/DataTransferObjects/HeartbeatEntry.php`
- `src/DataTransferObjects/MetricEntry.php`

### 重構

- `src/Support/RedisChannels.php` → `src/Support/ChannelRegistry.php`
- `src/ZenithServiceProvider.php`（DI 綁定）
- `src/Queue/ZenithQueueSubscriber.php`
- `src/Logging/ZenithLogHandler.php`
- `src/Http/Middleware/RecordRequestMetrics.php`
- `src/Console/ZenithHeartbeatCommand.php`
- `src/Console/ZenithCheckCommand.php`
- `config/zenith.php`

### 移除

- `src/Support/RedisPublisher.php`（搬移至 Transport/RedisTransport）
- `src/Support/RedisChannels.php`（被 ChannelRegistry 取代）

## 測試策略

- **DTO 單元測試**：驗證 `toArray()` 輸出正確的欄位名稱與型別
- **ChannelRegistry 單元測試**：驗證預設值、自訂覆寫、key 組合邏輯
- **RedisTransport 單元測試**：現有 `RedisPublisherTest` 重構，驗證 `TransportInterface` 實作
- **各元件測試更新**：mock `TransportInterface` 取代 mock Redis facade，驗證元件傳遞正確的 DTO
- **ServiceProvider 測試**：驗證 DI 綁定正確解析
