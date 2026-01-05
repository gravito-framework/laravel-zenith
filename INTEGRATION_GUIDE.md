# Laravel Zenith - Quick Integration Guide

**For testing in existing Laravel projects**

---

## Step 1: Install via Composer (Local Development)

In your existing Laravel project, add the local repository:

```bash
# Add local repository path
composer config repositories.laravel-zenith path /Users/carl/Dev/Carl/laravel-zenith

# Install the package
composer require gravito/laravel-zenith:@dev
```

---

## Step 2: Publish Configuration

```bash
php artisan vendor:publish --tag=zenith-config
```

This creates `config/zenith.php`.

---

## Step 3: Configure Redis Connection

### Option A: Use Existing Redis Connection

In `.env`:
```env
ZENITH_ENABLED=true
ZENITH_REDIS_CONNECTION=default
```

### Option B: Create Dedicated Connection (Recommended)

In `config/database.php`, add:
```php
'redis' => [
    // ... existing connections
    
    'zenith' => [
        'host' => env('ZENITH_REDIS_HOST', '127.0.0.1'),
        'password' => env('ZENITH_REDIS_PASSWORD', null),
        'port' => env('ZENITH_REDIS_PORT', '6379'),
        'database' => env('ZENITH_REDIS_DB', '0'),
        'prefix' => '', // Important: no prefix
    ],
],
```

In `.env`:
```env
ZENITH_ENABLED=true
ZENITH_REDIS_CONNECTION=zenith
ZENITH_REDIS_HOST=127.0.0.1
ZENITH_REDIS_PORT=6379
ZENITH_REDIS_DB=0
```

---

## Step 4: Verify Installation

```bash
php artisan zenith:check
```

Expected output:
```
🔍 Zenith Configuration Check

  ✓ Zenith Enabled
Redis Connection: zenith
  ✓ Redis Connection
Testing publish capability...
  ✓ Publish Test

📋 Configuration:
┌─────────────────┬───────────┐
│ Feature         │ Status    │
├─────────────────┼───────────┤
│ Logging         │ ✓ Enabled │
│ Queue Monitoring│ ✓ Enabled │
│ HTTP Monitoring │ ✓ Enabled │
└─────────────────┴───────────┘

✅ All checks passed! Zenith is ready.
```

---

## Step 5: Test Queue Monitoring

### 5.1 Create a Test Job (if you don't have one)

```bash
php artisan make:job TestZenithJob
```

In `app/Jobs/TestZenithJob.php`:
```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TestZenithJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        Log::info('TestZenithJob is running!');
        sleep(2); // Simulate work
        Log::info('TestZenithJob completed!');
    }
}
```

### 5.2 Dispatch the Job

In `tinker` or a route:
```bash
php artisan tinker
```

```php
App\Jobs\TestZenithJob::dispatch();
```

### 5.3 Run Queue Worker

```bash
php artisan queue:work
```

### 5.4 Check Redis for Logs

```bash
redis-cli SUBSCRIBE flux_console:logs
```

You should see:
```json
{
  "level": "info",
  "message": "Processing TestZenithJob",
  "workerId": "hostname-12345",
  "timestamp": "2026-01-05T15:30:00+08:00",
  "queue": "default"
}
```

---

## Step 6: Test Live Logging (Optional)

### 6.1 Add Zenith Log Channel

In `config/logging.php`:
```php
'channels' => [
    // ... existing channels
    
    'zenith' => [
        'driver' => 'zenith',
    ],
    
    // Or add to stack
    'stack' => [
        'driver' => 'stack',
        'channels' => ['daily', 'zenith'],
    ],
],
```

### 6.2 Test Logging

```php
use Illuminate\Support\Facades\Log;

Log::channel('zenith')->info('Hello from Laravel Zenith!');
Log::channel('zenith')->error('This is an error test');
```

Check Redis:
```bash
redis-cli SUBSCRIBE flux_console:logs
```

---

## Step 7: Test Worker Heartbeat (Optional)

Run in a separate terminal:
```bash
php artisan zenith:heartbeat
```

Expected output:
```
Starting Zenith Heartbeat (Worker ID: hostname-12345)
Interval: 5s, TTL: 30s

❤️  Heartbeat sent at 15:30:00
❤️  Heartbeat sent at 15:30:05
❤️  Heartbeat sent at 15:30:10
```

Check Redis:
```bash
redis-cli GET flux_console:worker:hostname-12345
```

---

## Step 8: Test HTTP Middleware (Optional)

### 8.1 Register Middleware

In `app/Http/Kernel.php`:
```php
protected $middleware = [
    // ... existing middleware
    \Gravito\Zenith\Laravel\Http\Middleware\RecordRequestMetrics::class,
];
```

### 8.2 Make HTTP Requests

Visit any route in your application, then check Redis:
```bash
redis-cli SUBSCRIBE flux_console:logs
```

You should see HTTP request logs for slow requests or errors.

---

## Troubleshooting

### Issue: "Redis connection failed"

**Solution**: Verify Redis is running:
```bash
redis-cli ping
# Should return: PONG
```

### Issue: "No logs appearing"

**Solution**: Check if Zenith is enabled:
```bash
php artisan tinker
>>> config('zenith.enabled')
=> true
```

### Issue: "Queue events not firing"

**Solution**: Make sure queue worker is running:
```bash
php artisan queue:work --verbose
```

---

## Viewing Logs in Zenith UI

1. Make sure Gravito Zenith server is running
2. Open Zenith UI in browser
3. Navigate to "Live Logs" section
4. You should see real-time logs streaming in

---

## Clean Up (After Testing)

To remove the package:
```bash
composer remove gravito/laravel-zenith
composer config --unset repositories.laravel-zenith
```

---

## Next Steps

- ✅ Test queue monitoring with real jobs
- ✅ Test logging with different log levels
- ✅ Test HTTP middleware with API endpoints
- ✅ Verify integration with Zenith UI
- 📦 If satisfied, prepare for Packagist publication

---

## Expected Redis Keys

After testing, you should see these keys in Redis:

```bash
redis-cli KEYS flux_console:*
```

Expected output:
```
1) "flux_console:logs:history"
2) "flux_console:worker:hostname-12345"
3) "flux_console:throughput:28123456"
4) "flux_console:metrics:waiting:28123456"
```

---

## Support

If you encounter issues:
1. Check `config/zenith.php` settings
2. Run `php artisan zenith:check`
3. Verify Redis connectivity
4. Check Laravel logs in `storage/logs/`
