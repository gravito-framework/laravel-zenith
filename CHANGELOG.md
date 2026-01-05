# Changelog

All notable changes to `gravito/laravel-zenith` will be documented in this file.

## [Unreleased]

### Added
- Initial release
- Live operational logs via custom Monolog handler
- Queue lifecycle event monitoring (JobProcessing, JobProcessed, JobFailed)
- Worker heartbeat command for process discovery
- HTTP request performance monitoring middleware
- Health check command (`zenith:check`)
- Comprehensive configuration options
- Fire-and-forget Redis publishing for zero-blocking performance
- Support for Laravel 10.x and 11.x
- PHP 8.1+ compatibility

### Features
- **Logging**: Stream Laravel logs to Zenith UI in real-time
- **Queue Monitoring**: Track job processing, completion, and failures
- **HTTP Metrics**: Monitor request performance, status codes, and slow requests
- **Worker Discovery**: Automatic worker process detection via heartbeat
- **Zero-Blocking**: All operations use fire-and-forget pattern

### Configuration
- Configurable Redis connection
- Feature toggles for logging, queues, and HTTP monitoring
- Customizable ignore patterns for jobs and HTTP paths
- Adjustable slow request threshold

## [0.1.0] - 2026-01-05

### Initial Development
- Package structure and PSR-4 autoloading
- Service provider with auto-discovery
- Core monitoring features (Phases 1-3)
