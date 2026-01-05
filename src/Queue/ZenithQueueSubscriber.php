<?php

namespace Gravito\Zenith\Laravel\Queue;

use Gravito\Zenith\Laravel\Support\RedisPublisher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;

/**
 * Queue event subscriber for Zenith monitoring.
 */
class ZenithQueueSubscriber
{
    protected RedisPublisher $publisher;
    protected string $workerId;
    protected array $ignoreJobs;

    public function __construct()
    {
        $this->publisher = new RedisPublisher();
        $this->workerId = $this->generateWorkerId();
        $this->ignoreJobs = config('zenith.queues.ignore_jobs', []);
    }

    /**
     * Register the listeners for the subscriber.
     */
    public function subscribe($events): void
    {
        if (!config('zenith.queues.enabled', true)) {
            return;
        }

        $events->listen(JobProcessing::class, [$this, 'handleJobProcessing']);
        $events->listen(JobProcessed::class, [$this, 'handleJobProcessed']);
        $events->listen(JobFailed::class, [$this, 'handleJobFailed']);
    }

    /**
     * Handle the JobProcessing event.
     */
    public function handleJobProcessing(JobProcessing $event): void
    {
        $jobName = $this->getJobName($event->job);

        if ($this->shouldIgnore($jobName)) {
            return;
        }

        $this->publishLog('info', "Processing {$jobName}", $event->job->getQueue());
    }

    /**
     * Handle the JobProcessed event.
     */
    public function handleJobProcessed(JobProcessed $event): void
    {
        $jobName = $this->getJobName($event->job);

        if ($this->shouldIgnore($jobName)) {
            return;
        }

        $this->publishLog('success', "Completed {$jobName}", $event->job->getQueue());

        // Increment throughput counter
        $minute = floor(time() / 60);
        $this->publisher->incr("flux_console:throughput:{$minute}", 3600);
    }

    /**
     * Handle the JobFailed event.
     */
    public function handleJobFailed(JobFailed $event): void
    {
        $jobName = $this->getJobName($event->job);

        if ($this->shouldIgnore($jobName)) {
            return;
        }

        $errorMessage = $event->exception->getMessage();
        $this->publishLog('error', "Failed {$jobName}: {$errorMessage}", $event->job->getQueue());

        // Also increment throughput for failed jobs
        $minute = floor(time() / 60);
        $this->publisher->incr("flux_console:throughput:{$minute}", 3600);
    }

    /**
     * Publish a log message to Zenith.
     */
    protected function publishLog(string $level, string $message, ?string $queue = null): void
    {
        $payload = [
            'level' => $level,
            'message' => $message,
            'workerId' => $this->workerId,
            'timestamp' => now()->toIso8601String(),
        ];

        if ($queue) {
            $payload['queue'] = $queue;
        }

        $this->publisher->publish('flux_console:logs', $payload);
    }

    /**
     * Get the job name from the job instance.
     */
    protected function getJobName($job): string
    {
        $payload = $job->payload();
        $displayName = $payload['displayName'] ?? 'Unknown Job';
        
        // Simplify class name (remove namespace)
        return class_basename($displayName);
    }

    /**
     * Check if a job should be ignored.
     */
    protected function shouldIgnore(string $jobName): bool
    {
        if (!config('zenith.queues.monitor_all', true)) {
            return true;
        }

        foreach ($this->ignoreJobs as $pattern) {
            if (fnmatch($pattern, $jobName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate a unique worker ID.
     */
    protected function generateWorkerId(): string
    {
        return gethostname() . '-' . getmypid();
    }
}
