<?php

namespace Gravito\Zenith\Laravel\Tests\Unit;

use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Gravito\Zenith\Laravel\Queue\ZenithQueueSubscriber;
use Gravito\Zenith\Laravel\Support\ChannelRegistry;
use Gravito\Zenith\Laravel\Tests\TestCase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Mockery;
use Mockery\MockInterface;

class ZenithQueueSubscriberTest extends TestCase
{
    protected ZenithQueueSubscriber $subscriber;
    protected MockInterface $transport;
    protected ChannelRegistry $channels;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = Mockery::mock(TransportInterface::class);
        $this->channels = new ChannelRegistry([]);

        $this->subscriber = new ZenithQueueSubscriber(
            $this->transport,
            $this->channels,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function handle_job_processing_publishes_info_log_to_transport(): void
    {
        config(['zenith.queues.monitor_all' => true]);

        $job = $this->makeJob('App\\Jobs\\SendEmailJob', 'default');

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(function (array $payload) {
                    return $payload['level'] === 'info'
                        && $payload['message'] === 'Processing SendEmailJob'
                        && $payload['context']['queue'] === 'default'
                        && isset($payload['workerId'])
                        && isset($payload['timestamp']);
                })
            );

        $event = new JobProcessing('redis', $job);
        $this->subscriber->handleJobProcessing($event);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function handle_job_processing_does_not_publish_when_job_is_ignored(): void
    {
        config([
            'zenith.queues.monitor_all' => true,
            'zenith.queues.ignore_jobs' => ['SendEmailJob'],
        ]);

        $subscriber = new ZenithQueueSubscriber($this->transport, $this->channels);

        $this->transport->shouldReceive('publish')->never();

        $job = $this->makeJob('App\\Jobs\\SendEmailJob', 'default');
        $event = new JobProcessing('redis', $job);
        $subscriber->handleJobProcessing($event);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function handle_job_processed_publishes_success_log_and_increments_throughput(): void
    {
        config(['zenith.queues.monitor_all' => true]);

        $job = $this->makeJob('App\\Jobs\\ProcessOrderJob', 'high');

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(function (array $payload) {
                    return $payload['level'] === 'success'
                        && $payload['message'] === 'Completed ProcessOrderJob'
                        && $payload['context']['queue'] === 'high';
                })
            );

        $this->transport
            ->shouldReceive('increment')
            ->once()
            ->with(
                Mockery::on(fn (string $key) => str_starts_with($key, 'zenith:throughput:')),
                3600
            );

        $event = new JobProcessed('redis', $job);
        $this->subscriber->handleJobProcessed($event);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function handle_job_processed_does_not_publish_when_job_is_ignored(): void
    {
        config([
            'zenith.queues.monitor_all' => true,
            'zenith.queues.ignore_jobs' => ['ProcessOrderJob'],
        ]);

        $subscriber = new ZenithQueueSubscriber($this->transport, $this->channels);

        $this->transport->shouldReceive('publish')->never();
        $this->transport->shouldReceive('increment')->never();

        $job = $this->makeJob('App\\Jobs\\ProcessOrderJob', 'high');
        $event = new JobProcessed('redis', $job);
        $subscriber->handleJobProcessed($event);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function handle_job_failed_publishes_error_log_with_exception_message(): void
    {
        config(['zenith.queues.monitor_all' => true]);

        $job = $this->makeJob('App\\Jobs\\ImportCsvJob', 'default');
        $exception = new \RuntimeException('Disk quota exceeded');

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(function (array $payload) {
                    return $payload['level'] === 'error'
                        && $payload['message'] === 'Failed ImportCsvJob: Disk quota exceeded'
                        && $payload['context']['queue'] === 'default';
                })
            );

        $this->transport
            ->shouldReceive('increment')
            ->once()
            ->with(
                Mockery::on(fn (string $key) => str_starts_with($key, 'zenith:throughput:')),
                3600
            );

        $event = new JobFailed('redis', $job, $exception);
        $this->subscriber->handleJobFailed($event);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function handle_job_failed_does_not_publish_when_job_is_ignored(): void
    {
        config([
            'zenith.queues.monitor_all' => true,
            'zenith.queues.ignore_jobs' => ['ImportCsvJob'],
        ]);

        $subscriber = new ZenithQueueSubscriber($this->transport, $this->channels);

        $this->transport->shouldReceive('publish')->never();
        $this->transport->shouldReceive('increment')->never();

        $job = $this->makeJob('App\\Jobs\\ImportCsvJob', 'default');
        $exception = new \RuntimeException('Disk quota exceeded');
        $event = new JobFailed('redis', $job, $exception);
        $subscriber->handleJobFailed($event);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function should_ignore_returns_true_for_jobs_matching_ignore_list(): void
    {
        config([
            'zenith.queues.monitor_all' => true,
            'zenith.queues.ignore_jobs' => ['SendEmailJob', 'Cleanup*'],
        ]);

        $subscriber = new ZenithQueueSubscriber($this->transport, $this->channels);

        $this->transport->shouldReceive('publish')->never();

        $job = $this->makeJob('App\\Jobs\\SendEmailJob', 'default');
        $event = new JobProcessing('redis', $job);
        $subscriber->handleJobProcessing($event);

        $job2 = $this->makeJob('App\\Jobs\\CleanupOldRecordsJob', 'default');
        $event2 = new JobProcessing('redis', $job2);
        $subscriber->handleJobProcessing($event2);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function should_ignore_returns_true_when_monitor_all_is_disabled(): void
    {
        config(['zenith.queues.monitor_all' => false]);

        $this->transport->shouldReceive('publish')->never();

        $job = $this->makeJob('App\\Jobs\\AnyJob', 'default');
        $event = new JobProcessing('redis', $job);
        $this->subscriber->handleJobProcessing($event);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function get_job_name_extracts_class_basename_from_job_payload(): void
    {
        config(['zenith.queues.monitor_all' => true]);

        $job = $this->makeJob('App\\Jobs\\Billing\\GenerateInvoiceJob', 'default');

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(fn (array $p) => $p['message'] === 'Processing GenerateInvoiceJob')
            );

        $event = new JobProcessing('redis', $job);
        $this->subscriber->handleJobProcessing($event);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function get_job_name_returns_unknown_job_when_display_name_is_missing(): void
    {
        config(['zenith.queues.monitor_all' => true]);

        $job = $this->makeJobWithPayload([], 'default');

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(fn (array $p) => $p['message'] === 'Processing Unknown Job')
            );

        $event = new JobProcessing('redis', $job);
        $this->subscriber->handleJobProcessing($event);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function get_job_name_returns_unknown_job_when_display_name_is_empty_string(): void
    {
        config(['zenith.queues.monitor_all' => true]);

        $job = $this->makeJobWithPayload(['displayName' => ''], 'default');

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(fn (array $p) => $p['message'] === 'Processing Unknown Job')
            );

        $event = new JobProcessing('redis', $job);
        $this->subscriber->handleJobProcessing($event);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function get_job_name_returns_unknown_job_when_payload_throws(): void
    {
        config(['zenith.queues.monitor_all' => true]);

        $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andThrow(new \RuntimeException('corrupt payload'));
        $job->shouldReceive('getQueue')->andReturn('default');

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(fn (array $p) => $p['message'] === 'Processing Unknown Job')
            );

        $event = new JobProcessing('redis', $job);
        $this->subscriber->handleJobProcessing($event);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function subscribe_does_nothing_when_queues_are_disabled(): void
    {
        config(['zenith.queues.enabled' => false]);

        $dispatcher = Mockery::mock(\Illuminate\Events\Dispatcher::class);
        $dispatcher->shouldReceive('listen')->never();

        $this->subscriber->subscribe($dispatcher);

        $this->addToAssertionCount(1);
    }

    /** @test */
    public function subscribe_registers_all_three_listeners_when_queues_are_enabled(): void
    {
        config(['zenith.queues.enabled' => true]);

        $registeredListeners = [];

        $dispatcher = Mockery::mock(\Illuminate\Events\Dispatcher::class);
        $dispatcher
            ->shouldReceive('listen')
            ->times(3)
            ->andReturnUsing(function (string $event) use (&$registeredListeners) {
                $registeredListeners[] = $event;
            });

        $this->subscriber->subscribe($dispatcher);

        $this->assertContains(JobProcessing::class, $registeredListeners);
        $this->assertContains(JobProcessed::class, $registeredListeners);
        $this->assertContains(JobFailed::class, $registeredListeners);
    }

    /** @test */
    public function it_uses_custom_channel_names(): void
    {
        config(['zenith.queues.monitor_all' => true]);

        $customChannels = new ChannelRegistry([
            'logs' => 'custom:logs',
            'throughput' => 'custom:throughput:',
        ]);
        $subscriber = new ZenithQueueSubscriber($this->transport, $customChannels);

        $job = $this->makeJob('App\\Jobs\\TestJob', 'default');

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with('custom:logs', Mockery::any());

        $this->transport
            ->shouldReceive('increment')
            ->once()
            ->with(
                Mockery::on(fn (string $key) => str_starts_with($key, 'custom:throughput:')),
                Mockery::any()
            );

        $event = new JobProcessed('redis', $job);
        $subscriber->handleJobProcessed($event);

        $this->addToAssertionCount(1);
    }

    private function makeJob(string $fullyQualifiedClassName, string $queue): \Illuminate\Contracts\Queue\Job
    {
        return $this->makeJobWithPayload(['displayName' => $fullyQualifiedClassName], $queue);
    }

    private function makeJobWithPayload(array $payload, string $queue): \Illuminate\Contracts\Queue\Job
    {
        $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $job->shouldReceive('payload')->andReturn($payload);
        $job->shouldReceive('getQueue')->andReturn($queue);

        return $job;
    }
}
