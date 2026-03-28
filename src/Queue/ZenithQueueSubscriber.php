<?php

namespace Gravito\Zenith\Laravel\Queue;

use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Gravito\Zenith\Laravel\DataTransferObjects\LogEntry;
use Gravito\Zenith\Laravel\Support\ChannelRegistry;
use Gravito\Zenith\Laravel\Support\GeneratesWorkerId;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

class ZenithQueueSubscriber
{
    use GeneratesWorkerId;

    protected string $workerId;
    protected array $ignoreJobs;

    public function __construct(
        protected TransportInterface $transport,
        protected ChannelRegistry $channels,
    ) {
        $this->workerId = $this->generateWorkerId();
        $this->ignoreJobs = config('zenith.queues.ignore_jobs', []);
    }

    public function subscribe($events): void
    {
        if (!config('zenith.queues.enabled', true)) {
            return;
        }

        $events->listen(JobProcessing::class, [$this, 'handleJobProcessing']);
        $events->listen(JobProcessed::class, [$this, 'handleJobProcessed']);
        $events->listen(JobFailed::class, [$this, 'handleJobFailed']);
    }

    public function handleJobProcessing(JobProcessing $event): void
    {
        $jobName = $this->getJobName($event->job);

        if ($this->shouldIgnore($jobName)) {
            return;
        }

        $this->publishLog('info', "Processing {$jobName}", $event->job->getQueue());
    }

    public function handleJobProcessed(JobProcessed $event): void
    {
        $jobName = $this->getJobName($event->job);

        if ($this->shouldIgnore($jobName)) {
            return;
        }

        $this->publishLog('success', "Completed {$jobName}", $event->job->getQueue());

        $minute = (int) floor(time() / 60);
        $this->transport->increment(
            $this->channels->throughputKey($minute),
            $this->channels->counterTtl(),
        );
    }

    public function handleJobFailed(JobFailed $event): void
    {
        $jobName = $this->getJobName($event->job);

        if ($this->shouldIgnore($jobName)) {
            return;
        }

        $errorMessage = $event->exception->getMessage();
        $this->publishLog('error', "Failed {$jobName}: {$errorMessage}", $event->job->getQueue());

        $minute = (int) floor(time() / 60);
        $this->transport->increment(
            $this->channels->throughputKey($minute),
            $this->channels->counterTtl(),
        );
    }

    protected function publishLog(string $level, string $message, ?string $queue = null): void
    {
        $entry = new LogEntry(
            level: $level,
            message: $message,
            workerId: $this->workerId,
            timestamp: now()->toIso8601String(),
            context: $queue ? ['queue' => $queue] : [],
        );

        $this->transport->publish($this->channels->logs(), $entry->toArray());
    }

    protected function getJobName($job): string
    {
        try {
            $payload = $job->payload();
            $displayName = $payload['displayName'] ?? null;

            if (!is_string($displayName) || $displayName === '') {
                return 'Unknown Job';
            }

            return class_basename($displayName);
        } catch (\Throwable $e) {
            return 'Unknown Job';
        }
    }

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
}
