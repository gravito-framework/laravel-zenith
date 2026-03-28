# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Laravel Zenith is a Composer package that provides real-time monitoring for Laravel applications. It acts as "The Reporter" — an event-driven, fire-and-forget observer that publishes application events via a pluggable transport layer (currently Redis). The transport and channel names are fully configurable, making it independent of any specific remote consumer.

## Common Commands

```bash
# Install dependencies
composer install

# Run all tests
./vendor/bin/phpunit

# Run unit tests only
./vendor/bin/phpunit --testsuite=Unit

# Run feature tests only
./vendor/bin/phpunit --testsuite=Feature

# Run a single test file
./vendor/bin/phpunit tests/Unit/RedisTransportTest.php

# Run a single test method
./vendor/bin/phpunit --filter=testMethodName

# Static analysis (PHPStan level 5)
./vendor/bin/phpstan analyse
```

## Architecture

### Namespace & Autoloading

- Source: `Gravito\Zenith\Laravel\` → `src/`
- Tests: `Gravito\Zenith\Laravel\Tests\` → `tests/`

### Transport Layer (Pluggable)

**TransportInterface** (`src/Contracts/TransportInterface.php`) — Contract defining `publish(string $topic, array $payload)`, `store(string $key, array $data, int $ttl)`, `increment(string $key, ?int $ttl)`, and `ping(): bool`. Parameters use transport-agnostic naming (e.g. `$topic` not `$channel`). All components depend on this interface, not a concrete implementation.

**TransportManager** (`src/Transport/TransportManager.php`) — Extends `Illuminate\Support\Manager` (Laravel Manager Pattern). Resolves the active transport driver from `config('zenith.transport.driver')`. Built-in drivers: `redis`, `null`. When `zenith.enabled = false`, auto-downgrades to `NullTransport`. Community packages register custom drivers via `TransportManager::extend('name', fn () => new CustomTransport(...))`.

**RedisTransport** (`src/Transport/RedisTransport.php`) — Redis implementation of `TransportInterface`. Constructor requires `string $connection`. Uses `Connection::command()` for all operations. Every operation is wrapped in try/catch to silently fail (zero-blocking philosophy). Does NOT check `zenith.enabled` — that's handled by `TransportManager`.

**NullTransport** (`src/Transport/NullTransport.php`) — No-op implementation. All methods do nothing, `ping()` returns `true`. Used when Zenith is disabled or in testing environments.

### Data Transfer Objects

**LogEntry** (`src/DataTransferObjects/LogEntry.php`) — Payload DTO for log events with `level`, `message`, `workerId`, `timestamp`, `context` fields.

**HeartbeatEntry** (`src/DataTransferObjects/HeartbeatEntry.php`) — Payload DTO for worker heartbeats with language-neutral field names (`memoryUsedMb`, `memoryPeakMb` instead of Node.js-specific `heapUsed`/`heapTotal`).

**MetricEntry** (`src/DataTransferObjects/MetricEntry.php`) — Payload DTO for counter metrics with `toKey(prefix)` for generating Redis keys.

### Core Components

**ZenithServiceProvider** (`src/ZenithServiceProvider.php`) — Auto-discovered via `composer.json` extra.laravel.providers. Registers `TransportManager` and `ChannelRegistry` as singletons. `TransportInterface` is bound as a singleton that resolves from `TransportManager::driver()`. Also registers config, commands, the custom `zenith` log driver, and the queue event subscriber. Runs `ConfigValidator::validate()` at boot when enabled.

**ZenithQueueSubscriber** (`src/Queue/ZenithQueueSubscriber.php`) — Event subscriber listening to `JobProcessing`, `JobProcessed`, `JobFailed`. Receives `TransportInterface` + `ChannelRegistry` via constructor injection. Uses `LogEntry` DTO for payloads.

**ZenithLogHandler** (`src/Logging/ZenithLogHandler.php`) — Monolog `AbstractProcessingHandler` that publishes log records via `TransportInterface`. Maps Monolog levels to Zenith levels (error/warn/info). Entire `write()` method is wrapped in try/catch since Monolog handlers must never throw.

**RecordRequestMetrics** (`src/Http/Middleware/RecordRequestMetrics.php`) — HTTP middleware. Receives `TransportInterface` + `ChannelRegistry` via constructor injection. Only logs noteworthy requests (errors or slow). Uses `LogEntry` DTO for payloads.

**Artisan Commands:**
- `zenith:check` — Health check: verifies config, transport connectivity, publish capability
- `zenith:heartbeat` — Long-running daemon that publishes worker status using `HeartbeatEntry` DTO

### Shared Infrastructure

**ChannelRegistry** (`src/Support/ChannelRegistry.php`) — Provides channel/key names read from `config('zenith.channels')`. Each channel is independently configurable. Default prefix is `zenith:` (language-neutral). Methods: `logs()`, `workerKey()`, `throughputKey()`, `httpMetricKey()`, `counterTtl()`.

**GeneratesWorkerId** (`src/Support/GeneratesWorkerId.php`) — Trait providing `generateWorkerId()` (`hostname-pid`). Used by QueueSubscriber, LogHandler, and HeartbeatCommand.

**ConfigValidator** (`src/Support/ConfigValidator.php`) — Validates Zenith config at boot: `transport.driver` must be a non-empty string, numeric ranges for `slow_threshold`/`interval`/`ttl`, TTL >= interval constraint, string array validation for `ignore_jobs`/`ignore_paths`.

### Channel/Key Convention

All channel and key names are configurable via `config('zenith.channels')`:
- `logs` (default: `zenith:logs`) — pub/sub channel for all log events
- `worker` (default: `zenith:worker:`) — key prefix for worker heartbeats
- `throughput` (default: `zenith:throughput:`) — counter prefix per minute
- `http` (default: `zenith:metrics:http:`) — HTTP metrics counters
- `counter_ttl` (default: 3600) — default 1-hour TTL for per-minute counters

### Testing

Tests use Orchestra Testbench (`tests/TestCase.php` extends `Orchestra\Testbench\TestCase`). The base test case auto-registers `ZenithServiceProvider` and sets default Zenith config. Components are tested by mocking `TransportInterface` via Mockery (no Redis facade mocking needed). Protected methods are tested via `ReflectionMethod` where needed.

### CI/CD

GitHub Actions (`.github/workflows/tests.yml`) runs on push/PR to main:
- Matrix: PHP 8.1-8.3 × Laravel 10-11 (excluding PHP 8.1 + Laravel 11)
- Redis service container for integration tests
- PHPStan level 5 static analysis

### Configuration

Published via `vendor:publish --tag=zenith-config` to `config/zenith.php`. All features can be independently enabled/disabled. Config is validated at boot by `ConfigValidator`.

Transport config lives under `zenith.transport`:
- `driver` (default: `'redis'`, env: `ZENITH_TRANSPORT`) — transport driver name
- `connection` (default: `'default'`, env: `ZENITH_REDIS_CONNECTION`) — Redis connection name (only used by Redis driver)

Community drivers are registered via `TransportManager::extend()` in a ServiceProvider and activated by setting `transport.driver` in config.

### Adding a Custom Transport Driver

```php
// 1. Implement TransportInterface
class DatadogTransport implements TransportInterface { ... }

// 2. Register in your ServiceProvider
$this->app->resolving(TransportManager::class, function ($manager) {
    $manager->extend('datadog', fn () => new DatadogTransport(...));
});

// 3. User sets config: 'transport.driver' => 'datadog'
```
