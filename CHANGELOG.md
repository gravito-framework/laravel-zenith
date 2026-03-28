# Changelog

All notable changes to `gravito/laravel-zenith` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [0.2.0] - 2026-03-28

### Added
- `TransportInterface` (`src/Contracts/`) — pluggable transport contract with `publish()`, `store()`, `increment()`, `ping()`
- `RedisTransport` (`src/Transport/`) — Redis implementation of `TransportInterface`
- `LogEntry`, `HeartbeatEntry`, `MetricEntry` DTOs (`src/DataTransferObjects/`) — formal, language-neutral payload contracts
- `ChannelRegistry` (`src/Support/`) — configurable channel/key names via `config('zenith.channels')`
- `config('zenith.channels')` block with per-channel env var overrides
- `GeneratesWorkerId` trait to eliminate duplicated worker ID generation
- `ConfigValidator` with boot-time validation of config values
- PHPStan level 5 static analysis (`phpstan.neon`)
- GitHub Actions CI matrix (PHP 8.1-8.3 × Laravel 10-11)
- `.gitattributes` for leaner package distribution
- Comprehensive test suite (84 tests, 165 assertions)

### Changed
- All components use constructor-injected `TransportInterface` + `ChannelRegistry` instead of direct `RedisPublisher` instantiation
- `ZenithServiceProvider` registers `TransportInterface` and `ChannelRegistry` as singletons
- `ZenithLogHandler` uses `LogEntry` DTO for payload construction
- `ZenithQueueSubscriber` uses `LogEntry` DTO; queue info moved to `context` field
- `RecordRequestMetrics` uses `LogEntry` DTO for payload construction
- `ZenithHeartbeatCommand` uses `HeartbeatEntry` DTO with language-neutral fields (`memoryUsedMb`/`memoryPeakMb` replace `heapUsed`/`heapTotal`/`rss`)
- `ZenithCheckCommand` uses `LogEntry` DTO for health check payload
- Default channel prefix changed from `flux_console:` to `zenith:`
- `RecordRequestMetrics::shouldIgnorePath()` uses `fnmatch()` instead of naive regex
- `ZenithLogHandler::write()` wrapped in try/catch (Monolog handlers must never throw)
- `ZenithQueueSubscriber::getJobName()` wrapped in try/catch with type validation

### Fixed
- Regex injection risk in HTTP path ignore patterns
- Potential crash in `getJobName()` on corrupted job payloads
- Potential crash in `getConcurrency()` when Horizon config has empty supervisors
- Missing error boundary in Monolog log handler

### Removed
- `RedisPublisher` — replaced by `TransportInterface` + `RedisTransport`
- `RedisChannels` — replaced by `ChannelRegistry`
- `minimum-stability: dev` from `composer.json`

## [0.1.0] - 2026-01-05

### Added
- Initial package structure with PSR-4 autoloading
- Service provider with Laravel auto-discovery
- Live operational logs via custom Monolog handler
- Queue lifecycle event monitoring (JobProcessing, JobProcessed, JobFailed)
- Worker heartbeat command (`zenith:heartbeat`) for process discovery
- HTTP request performance monitoring middleware
- Health check command (`zenith:check`)
- Fire-and-forget Redis publishing for zero-blocking performance
- Configurable Redis connection, feature toggles, ignore patterns
- Support for Laravel 10.x and 11.x, PHP 8.1+
