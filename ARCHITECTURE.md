# Laravel Zenith Architecture & Role Division

**Version**: 1.0  
**Last Updated**: 2026-01-05

---

## Overview

Laravel Zenith is part of the **Gravito Zenith** monitoring ecosystem. This document clarifies the role division between Laravel Zenith (this package) and Quasar Agent.

---

## The Two-Agent Model

```
┌─────────────────────────────────────────────────────────────┐
│ Laravel Application                                         │
│                                                             │
│  ┌──────────────────────┐      ┌──────────────────────┐   │
│  │ Laravel Zenith       │      │ Quasar Agent         │   │
│  │ (Composer Package)   │      │ (Sidecar Daemon)     │   │
│  │                      │      │                      │   │
│  │ Role: "The Reporter" │      │ Role: "The Scanner"  │   │
│  │ - Event Listener     │      │ - Redis Scanner      │   │
│  │ - Inside App         │      │ - OS Monitor         │   │
│  └──────────────────────┘      └──────────────────────┘   │
│           │                              │                 │
└───────────┼──────────────────────────────┼─────────────────┘
            │                              │
            ▼                              ▼
    ┌───────────────────────────────────────────────┐
    │ Redis (Gravito Pulse Protocol)                │
    │ - flux_console:logs                           │
    │ - flux_console:worker:{id}                    │
    │ - flux_console:metrics:*                      │
    └───────────────────────────────────────────────┘
                        │
                        ▼
            ┌───────────────────────┐
            │ Gravito Zenith UI     │
            │ (Control Plane)       │
            └───────────────────────┘
```

---

## Role Division

### Laravel Zenith (This Package)

**Type**: Composer package installed **inside** the Laravel application  
**Philosophy**: Event-driven, passive listener  
**Visibility**: Application-level events that OS cannot see

| Feature | What It Does | What It Doesn't Do |
|---------|--------------|-------------------|
| **Queue Events** | ✅ Listens to `JobProcessing`, `JobProcessed`, `JobFailed` | ❌ Does NOT scan Redis for queue statistics |
| **Live Logs** | ✅ Streams Laravel logs to Zenith UI | ❌ Does NOT collect system logs |
| **Worker Heartbeat** | ✅ Reports worker process status (PID, memory, uptime) | ❌ Does NOT monitor CPU/disk at OS level |
| **HTTP Metrics** | ✅ Tracks request duration, status codes, errors | ❌ Does NOT monitor network traffic |

**Key Characteristics**:
- 🎯 **Reactive**: Only reports when events happen
- 🚀 **Zero-blocking**: Fire-and-forget Redis publishing
- 📦 **Lightweight**: No background scanning or polling
- 🔍 **Deep visibility**: Sees application internals

### Quasar Agent

**Type**: Standalone daemon (Node.js or Go) running as sidecar  
**Philosophy**: Active scanner, infrastructure monitor  
**Visibility**: OS-level metrics and Redis data structures

| Feature | What It Does | What Laravel Zenith Cannot Do |
|---------|--------------|-------------------------------|
| **Queue Statistics** | ✅ Scans Redis keys (`queues:*`) for waiting/delayed/failed counts | ❌ Laravel Zenith only knows when jobs are **processed** |
| **System Metrics** | ✅ Monitors CPU, memory, disk, network at OS level | ❌ Laravel Zenith only sees PHP process memory |
| **Worker Discovery** | ✅ Scans OS processes for `php artisan queue:work` | ❌ Laravel Zenith requires manual heartbeat command |
| **Remote Control** | ✅ Executes artisan commands (`queue:retry`, `queue:restart`) | ❌ Laravel Zenith is passive, no command execution |

**Key Characteristics**:
- 🔄 **Proactive**: Actively scans and polls
- 🖥️ **OS-level**: Sees system resources
- 🎛️ **Control**: Can execute commands
- 📊 **Statistics**: Provides aggregate metrics

---

## Job Lifecycle Example

Let's trace what happens when a job is dispatched:

```php
// In your Laravel application
SendWelcomeEmail::dispatch($user);
```

### Timeline

| Time | Event | Laravel Zenith | Quasar Agent | Zenith UI |
|------|-------|----------------|--------------|-----------|
| **T+0s** | Job dispatched | ❌ No notification | ✅ Next scan will see +1 waiting | Shows queue size increase |
| **T+1s** | Job enters Redis `queues:default` | ❌ Not aware | ✅ Scans and reports: "1 waiting" | Updates queue stats |
| **T+2s** | Worker picks up job | ✅ `JobProcessing` event fired | ❌ Not involved | Shows "Processing..." |
| **T+2s** | Laravel Zenith logs | ✅ Publishes: "Processing SendWelcomeEmail" | ❌ Not involved | Displays log entry |
| **T+5s** | Job completes | ✅ `JobProcessed` event fired | ❌ Not involved | Shows "Completed" |
| **T+5s** | Laravel Zenith logs | ✅ Publishes: "Completed SendWelcomeEmail" | ❌ Not involved | Displays log entry |
| **T+5s** | Throughput metric | ✅ Increments `flux_console:throughput:{minute}` | ❌ Not involved | Updates throughput graph |
| **T+6s** | Queue scan | ❌ Not involved | ✅ Reports: "0 waiting" | Updates queue stats |

### Key Insights

1. **Laravel Zenith sees the "story"** (what happened to this specific job)
2. **Quasar Agent sees the "numbers"** (how many jobs are waiting)
3. **Both are needed** for complete visibility

---

## What You See in Zenith UI

### With Laravel Zenith Only

✅ **You Will See**:
- Live log stream: "Processing SendEmail", "Completed SendEmail"
- Job failure details with exception messages
- HTTP request logs (slow requests, errors)
- Worker heartbeat (if `zenith:heartbeat` is running)
- Throughput graph (jobs per minute)

❌ **You Will NOT See**:
- Queue statistics (e.g., "5 jobs waiting in default queue")
- System resource usage (CPU, disk, network)
- Worker process discovery (unless heartbeat is manually started)
- Ability to retry/delete jobs from UI

### With Quasar Agent Only

✅ **You Will See**:
- Queue statistics (waiting, delayed, failed counts)
- System metrics (CPU, memory, disk, load average)
- Worker process list (auto-discovered)
- Remote control capabilities (retry jobs, restart workers)

❌ **You Will NOT See**:
- Real-time job processing logs
- Application-level error details
- HTTP request performance
- Custom Laravel logs

### With Both (Recommended)

✅ **Complete Visibility**:
- 📊 Queue statistics + 📝 Job execution logs
- 🖥️ System metrics + 💻 Application metrics
- 🔍 Worker discovery + ❤️ Worker health
- 🎛️ Remote control + 📡 Event streaming

---

## Installation Scenarios

### Scenario 1: Laravel Zenith Only (Minimal Setup)

```bash
composer require gravito/laravel-zenith
php artisan vendor:publish --tag=zenith-config
php artisan zenith:heartbeat  # Optional, for worker discovery
```

**Use Case**: You only need job execution logs and don't care about queue statistics.

**Limitations**:
- No queue size visibility
- No system resource monitoring
- No remote control from UI

### Scenario 2: Quasar Agent Only (External Monitoring)

```bash
# Install Quasar Agent (Node.js or Go)
npm install -g @gravito/quasar
# or
docker run gravito/quasar-go
```

**Use Case**: You want queue statistics and system monitoring without modifying Laravel code.

**Limitations**:
- No job execution logs
- No application-level error details
- No HTTP request tracking

### Scenario 3: Both (Full Stack) ⭐ Recommended

```bash
# Install Laravel Zenith
composer require gravito/laravel-zenith

# Install Quasar Agent
docker run gravito/quasar-go
```

**Use Case**: Production monitoring with complete visibility.

**Benefits**:
- ✅ Complete job lifecycle visibility
- ✅ Queue statistics + execution logs
- ✅ System + application metrics
- ✅ Remote control capabilities

---

## Data Flow Diagram

```
┌─────────────────────────────────────────────────────────┐
│ Laravel Application                                     │
│                                                         │
│  User Code:                                             │
│  SendEmail::dispatch($user) ──┐                         │
│                               │                         │
│                               ▼                         │
│                         Redis: queues:default           │
│                               │                         │
│                               │ (Job waiting)           │
│                               │                         │
│  Queue Worker:                │                         │
│  php artisan queue:work ──────┤                         │
│                               │                         │
│                               ▼                         │
│  ┌────────────────────────────────────────┐            │
│  │ Job Execution                          │            │
│  │                                        │            │
│  │ Laravel Events:                        │            │
│  │ - JobProcessing  ──┐                   │            │
│  │ - JobProcessed   ──┼─► Laravel Zenith │            │
│  │ - JobFailed      ──┘   (Listener)     │            │
│  │                        │               │            │
│  │                        ▼               │            │
│  │                  Publish to Redis      │            │
│  │                  flux_console:logs     │            │
│  └────────────────────────────────────────┘            │
│                                                         │
└─────────────────────────────────────────────────────────┘
                               │
                               │
        ┌──────────────────────┼──────────────────────┐
        │                      │                      │
        ▼                      ▼                      ▼
  Quasar Agent           Redis Keys            Zenith UI
  (Scanner)              (GPP Protocol)        (Consumer)
  │                      │                      │
  ├─ Scan queues:*       ├─ flux_console:logs  ├─ Display logs
  ├─ Monitor system      ├─ flux_console:worker├─ Show queue stats
  └─ Publish metrics     └─ flux_console:metrics└─ Render graphs
```

---

## Common Questions

### Q: Do I need both Laravel Zenith and Quasar Agent?

**A**: It depends on your needs:
- **Development**: Laravel Zenith only (for job logs)
- **Production**: Both (for complete monitoring)
- **External monitoring**: Quasar Agent only (if you can't modify Laravel code)

### Q: Why doesn't Laravel Zenith scan Redis for queue statistics?

**A**: By design. Laravel Zenith follows the "zero-blocking" philosophy:
- Scanning Redis would add latency to your application
- Quasar Agent (external daemon) is better suited for polling
- Laravel Zenith focuses on event-driven reporting

### Q: Can Laravel Zenith work without Quasar Agent?

**A**: Yes! Laravel Zenith is fully functional standalone. You'll get:
- ✅ Job execution logs
- ✅ HTTP request tracking
- ✅ Worker heartbeat
- ❌ No queue statistics
- ❌ No system metrics

### Q: When does Laravel Zenith send data?

**A**: Only when events occur:
- When a job starts processing
- When a job completes or fails
- When an HTTP request finishes (if middleware is enabled)
- Every 5 seconds (if heartbeat command is running)

**It does NOT**:
- Poll Redis continuously
- Scan for jobs in the queue
- Monitor system resources

---

## Best Practices

### For Development

```bash
# Minimal setup - just install Laravel Zenith
composer require gravito/laravel-zenith
php artisan vendor:publish --tag=zenith-config

# Optional: Run heartbeat in a separate terminal
php artisan zenith:heartbeat
```

### For Production

```bash
# Install both agents
composer require gravito/laravel-zenith

# Run Quasar Agent as Docker container
docker run -d \
  --name quasar-agent \
  -e QUASAR_TRANSPORT_REDIS_URL=redis://redis:6379 \
  gravito/quasar-go

# Run heartbeat via Supervisor
# See README.md for Supervisor configuration
```

### For CI/CD

```bash
# Disable Zenith in testing
# .env.testing
ZENITH_ENABLED=false
```

---

## Summary Table

| Capability | Laravel Zenith | Quasar Agent | Required For |
|------------|----------------|--------------|--------------|
| Job execution logs | ✅ | ❌ | Debugging job failures |
| Queue statistics | ❌ | ✅ | Monitoring queue backlog |
| HTTP request tracking | ✅ | ❌ | API performance monitoring |
| System metrics | ❌ | ✅ | Infrastructure monitoring |
| Worker heartbeat | ✅ (manual) | ✅ (auto) | Worker discovery |
| Remote control | ❌ | ✅ | Job retry/restart from UI |
| Custom Laravel logs | ✅ | ❌ | Application debugging |
| Zero-blocking | ✅ | ✅ | Production safety |

---

## Conclusion

**Laravel Zenith** and **Quasar Agent** are complementary:

- **Laravel Zenith** = "What happened?" (Events, logs, execution details)
- **Quasar Agent** = "What's the status?" (Statistics, metrics, control)

For **complete monitoring**, use both. For **minimal setup**, Laravel Zenith alone provides valuable job execution visibility.
