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

### Transport Layer

**TransportInterface** (`src/Contracts/TransportInterface.php`) — Contract defining `publish()`, `store()`, `increment()`, and `ping()`. All components depend on this interface, not a concrete implementation.

**RedisTransport** (`src/Transport/RedisTransport.php`) — Redis implementation of `TransportInterface`. Uses `Connection::command()` for all operations. Every operation is wrapped in try/catch to silently fail (zero-blocking philosophy).

### Data Transfer Objects

**LogEntry** (`src/DataTransferObjects/LogEntry.php`) — Payload DTO for log events with `level`, `message`, `workerId`, `timestamp`, `context` fields.

**HeartbeatEntry** (`src/DataTransferObjects/HeartbeatEntry.php`) — Payload DTO for worker heartbeats with language-neutral field names (`memoryUsedMb`, `memoryPeakMb` instead of Node.js-specific `heapUsed`/`heapTotal`).

**MetricEntry** (`src/DataTransferObjects/MetricEntry.php`) — Payload DTO for counter metrics with `toKey(prefix)` for generating Redis keys.

### Core Components

**ZenithServiceProvider** (`src/ZenithServiceProvider.php`) — Auto-discovered via `composer.json` extra.laravel.providers. Registers `TransportInterface` and `ChannelRegistry` as singletons, config, commands, the custom `zenith` log driver, and the queue event subscriber. Runs `ConfigValidator::validate()` at boot when enabled. All features gate on `config('zenith.enabled')`.

**ZenithQueueSubscriber** (`src/Queue/ZenithQueueSubscriber.php`) — Event subscriber listening to `JobProcessing`, `JobProcessed`, `JobFailed`. Receives `TransportInterface` + `ChannelRegistry` via constructor injection. Uses `LogEntry` DTO for payloads.

**ZenithLogHandler** (`src/Logging/ZenithLogHandler.php`) — Monolog `AbstractProcessingHandler` that publishes log records via `TransportInterface`. Maps Monolog levels to Zenith levels (error/warn/info). Entire `write()` method is wrapped in try/catch since Monolog handlers must never throw.

**RecordRequestMetrics** (`src/Http/Middleware/RecordRequestMetrics.php`) — HTTP middleware. Receives `TransportInterface` + `ChannelRegistry` via constructor injection. Only logs noteworthy requests (errors or slow). Uses `LogEntry` DTO for payloads.

**Artisan Commands:**
- `zenith:check` — Health check: verifies config, transport connectivity, publish capability
- `zenith:heartbeat` — Long-running daemon that publishes worker status using `HeartbeatEntry` DTO

### Shared Infrastructure

**ChannelRegistry** (`src/Support/ChannelRegistry.php`) — Provides channel/key names read from `config('zenith.channels')`. Each channel is independently configurable. Default prefix is `zenith:` (language-neutral). Methods: `logs()`, `workerKey()`, `throughputKey()`, `httpMetricKey()`, `counterTtl()`.

**GeneratesWorkerId** (`src/Support/GeneratesWorkerId.php`) — Trait providing `generateWorkerId()` (`hostname-pid`). Used by QueueSubscriber, LogHandler, and HeartbeatCommand.

**ConfigValidator** (`src/Support/ConfigValidator.php`) — Validates Zenith config at boot: numeric ranges for `slow_threshold`/`interval`/`ttl`, TTL >= interval constraint, string array validation for `ignore_jobs`/`ignore_paths`.

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

Published via `vendor:publish --tag=zenith-config` to `config/zenith.php`. All features can be independently enabled/disabled. Config is validated at boot by `ConfigValidator`. The package uses a dedicated Redis connection (`ZENITH_REDIS_CONNECTION` env var).
