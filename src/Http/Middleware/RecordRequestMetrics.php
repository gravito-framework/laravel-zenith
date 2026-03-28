<?php

namespace Gravito\Zenith\Laravel\Http\Middleware;

use Closure;
use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Gravito\Zenith\Laravel\DataTransferObjects\LogEntry;
use Gravito\Zenith\Laravel\Support\ChannelRegistry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordRequestMetrics
{
    protected array $ignorePaths;
    protected int $slowThreshold;

    public function __construct(
        protected TransportInterface $transport,
        protected ChannelRegistry $channels,
    ) {
        $this->ignorePaths = config('zenith.http.ignore_paths', []);
        $this->slowThreshold = config('zenith.http.slow_threshold', 1000);
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!config('zenith.enabled', true) || !config('zenith.http.enabled', true)) {
            return $next($request);
        }

        if ($this->shouldIgnorePath($request->path())) {
            return $next($request);
        }

        $startTime = microtime(true);

        $response = $next($request);

        $duration = (microtime(true) - $startTime) * 1000;

        $this->recordMetrics($request, $response, $duration);

        return $response;
    }

    protected function recordMetrics(Request $request, Response $response, float $duration): void
    {
        $statusCode = $response->getStatusCode();
        $route = $request->route();
        $routeName = 'Unknown';
        if ($route instanceof \Illuminate\Routing\Route) {
            $routeName = $route->getName() ?? $route->getActionName();
        }

        $level = $this->determineLevel($statusCode, $duration);

        if ($level === 'info' && $duration < $this->slowThreshold) {
            return;
        }

        $message = $this->formatMessage($request, $statusCode, $duration, $routeName);

        $entry = new LogEntry(
            level: $level,
            message: $message,
            workerId: gethostname() . '-http',
            timestamp: now()->toIso8601String(),
            context: [
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => $statusCode,
                'duration' => round($duration, 2),
                'route' => $routeName,
            ],
        );

        $this->transport->publish($this->channels->logs(), $entry->toArray());

        $this->incrementMetrics($statusCode, $duration);
    }

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

    protected function formatMessage(Request $request, int $statusCode, float $duration, string $routeName): string
    {
        $method = $request->method();
        $path = $request->path();
        $durationFormatted = round($duration, 2) . 'ms';

        if ($statusCode >= 400) {
            return "HTTP {$statusCode} {$method} /{$path} ({$durationFormatted})";
        }

        if ($duration >= $this->slowThreshold) {
            return "Slow Request: {$method} /{$path} ({$durationFormatted})";
        }

        return "HTTP {$statusCode} {$method} /{$path} ({$durationFormatted})";
    }

    protected function incrementMetrics(int $statusCode, float $duration): void
    {
        $minute = (int) floor(time() / 60);
        $ttl = $this->channels->counterTtl();

        $statusCategory = $this->getStatusCategory($statusCode);
        $this->transport->increment(
            $this->channels->httpMetricKey($statusCategory, $minute),
            $ttl,
        );

        if ($duration >= $this->slowThreshold) {
            $this->transport->increment(
                $this->channels->httpMetricKey('slow', $minute),
                $ttl,
            );
        }
    }

    protected function getStatusCategory(int $statusCode): string
    {
        return substr((string) $statusCode, 0, 1) . 'xx';
    }

    protected function shouldIgnorePath(string $path): bool
    {
        $path = '/' . ltrim($path, '/');

        foreach ($this->ignorePaths as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
