<?php

namespace Gravito\Zenith\Laravel\Http\Middleware;

use Closure;
use Gravito\Zenith\Laravel\Support\RedisPublisher;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to record HTTP request metrics.
 */
class RecordRequestMetrics
{
    protected RedisPublisher $publisher;
    protected array $ignorePaths;
    protected int $slowThreshold;

    public function __construct()
    {
        $this->publisher = new RedisPublisher();
        $this->ignorePaths = config('zenith.http.ignore_paths', []);
        $this->slowThreshold = config('zenith.http.slow_threshold', 1000);
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('zenith.enabled', true) || !config('zenith.http.enabled', true)) {
            return $next($request);
        }

        // Check if path should be ignored
        if ($this->shouldIgnorePath($request->path())) {
            return $next($request);
        }

        $startTime = microtime(true);

        $response = $next($request);

        $duration = (microtime(true) - $startTime) * 1000; // Convert to milliseconds

        $this->recordMetrics($request, $response, $duration);

        return $response;
    }

    /**
     * Record request metrics to Zenith.
     */
    protected function recordMetrics(Request $request, Response $response, float $duration): void
    {
        $statusCode = $response->getStatusCode();
        $route = $request->route();
        $routeName = $route ? ($route->getName() ?? $route->getActionName()) : 'Unknown';

        // Determine log level based on status code and duration
        $level = $this->determineLevel($statusCode, $duration);

        // Only log if it's noteworthy (errors or slow requests)
        if ($level === 'info' && $duration < $this->slowThreshold) {
            return; // Skip normal fast requests to reduce noise
        }

        $message = $this->formatMessage($request, $statusCode, $duration, $routeName);

        $this->publisher->publish('flux_console:logs', [
            'level' => $level,
            'message' => $message,
            'workerId' => gethostname() . '-http',
            'timestamp' => now()->toIso8601String(),
            'context' => [
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => $statusCode,
                'duration' => round($duration, 2),
                'route' => $routeName,
            ],
        ]);

        // Increment metrics counters
        $this->incrementMetrics($statusCode, $duration);
    }

    /**
     * Determine log level based on status code and duration.
     */
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

    /**
     * Format the log message.
     */
    protected function formatMessage(Request $request, int $statusCode, float $duration, string $routeName): string
    {
        $method = $request->method();
        $path = $request->path();
        $durationFormatted = round($duration, 2) . 'ms';

        if ($statusCode >= 500) {
            return "HTTP {$statusCode} {$method} /{$path} ({$durationFormatted})";
        }

        if ($statusCode >= 400) {
            return "HTTP {$statusCode} {$method} /{$path} ({$durationFormatted})";
        }

        if ($duration >= $this->slowThreshold) {
            return "Slow Request: {$method} /{$path} ({$durationFormatted})";
        }

        return "HTTP {$statusCode} {$method} /{$path} ({$durationFormatted})";
    }

    /**
     * Increment metrics counters.
     */
    protected function incrementMetrics(int $statusCode, float $duration): void
    {
        $minute = floor(time() / 60);

        // Increment status code counter
        $statusCategory = $this->getStatusCategory($statusCode);
        $this->publisher->incr("flux_console:metrics:http:{$statusCategory}:{$minute}", 3600);

        // Increment slow request counter if applicable
        if ($duration >= $this->slowThreshold) {
            $this->publisher->incr("flux_console:metrics:http:slow:{$minute}", 3600);
        }
    }

    /**
     * Get status code category (2xx, 4xx, 5xx).
     */
    protected function getStatusCategory(int $statusCode): string
    {
        return substr((string) $statusCode, 0, 1) . 'xx';
    }

    /**
     * Check if the request path should be ignored.
     */
    protected function shouldIgnorePath(string $path): bool
    {
        foreach ($this->ignorePaths as $pattern) {
            // Convert wildcard pattern to regex
            $regex = str_replace('*', '.*', $pattern);
            $regex = '#^' . $regex . '$#';

            if (preg_match($regex, $path)) {
                return true;
            }
        }

        return false;
    }
}
