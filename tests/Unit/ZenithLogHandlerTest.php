<?php

namespace Gravito\Zenith\Laravel\Tests\Unit;

use DateTimeImmutable;
use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Gravito\Zenith\Laravel\Logging\ZenithLogHandler;
use Gravito\Zenith\Laravel\Logging\ZenithLogger;
use Gravito\Zenith\Laravel\Support\ChannelRegistry;
use Gravito\Zenith\Laravel\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Logger;

class ZenithLogHandlerTest extends TestCase
{
    protected ZenithLogHandler $handler;
    protected MockInterface $transport;
    protected ChannelRegistry $channels;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = Mockery::mock(TransportInterface::class);
        $this->channels = new ChannelRegistry([]);
        $this->handler = new ZenithLogHandler(
            transport: $this->transport,
            channels: $this->channels,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeRecord(
        Level $level = Level::Info,
        string $message = 'test message',
        array $context = []
    ): LogRecord {
        return new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'test',
            level: $level,
            message: $message,
            context: $context,
        );
    }

    /** @test */
    public function write_publishes_log_entry_to_transport(): void
    {
        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(function (array $payload) {
                    return $payload['level'] === 'info'
                        && $payload['message'] === 'hello world'
                        && isset($payload['workerId'])
                        && isset($payload['timestamp'])
                        && is_array($payload['context']);
                })
            );

        $this->handler->handle($this->makeRecord(Level::Info, 'hello world'));
        $this->addToAssertionCount(1);
    }

    /** @test */
    public function write_does_nothing_when_logging_is_disabled(): void
    {
        config(['zenith.logging.enabled' => false]);

        $this->transport->shouldReceive('publish')->never();

        $this->handler->handle($this->makeRecord(Level::Error, 'should not publish'));
        $this->addToAssertionCount(1);
    }

    /** @test */
    public function map_level_maps_error_to_error(): void
    {
        $this->assertLevelMapsTo('error', Level::Error);
    }

    /** @test */
    public function map_level_maps_critical_to_error(): void
    {
        $this->assertLevelMapsTo('error', Level::Critical);
    }

    /** @test */
    public function map_level_maps_alert_to_error(): void
    {
        $this->assertLevelMapsTo('error', Level::Alert);
    }

    /** @test */
    public function map_level_maps_emergency_to_error(): void
    {
        $this->assertLevelMapsTo('error', Level::Emergency);
    }

    /** @test */
    public function map_level_maps_warning_to_warn(): void
    {
        $this->assertLevelMapsTo('warn', Level::Warning);
    }

    /** @test */
    public function map_level_maps_info_to_info(): void
    {
        $this->assertLevelMapsTo('info', Level::Info);
    }

    /** @test */
    public function map_level_maps_debug_to_info(): void
    {
        $this->assertLevelMapsTo('info', Level::Debug);
    }

    /** @test */
    public function map_level_maps_notice_to_info(): void
    {
        $this->assertLevelMapsTo('info', Level::Notice);
    }

    private function assertLevelMapsTo(string $expected, Level $level): void
    {
        $capturedLevel = null;

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(function (array $payload) use (&$capturedLevel) {
                    $capturedLevel = $payload['level'] ?? null;
                    return true;
                })
            );

        $this->handler->handle($this->makeRecord($level));

        $this->assertSame($expected, $capturedLevel);
    }

    /** @test */
    public function write_includes_queue_context_in_payload(): void
    {
        $capturedPayload = null;

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(function (array $payload) use (&$capturedPayload) {
                    $capturedPayload = $payload;
                    return true;
                })
            );

        $record = $this->makeRecord(Level::Info, 'job processed', ['queue' => 'emails']);
        $this->handler->handle($record);

        $this->assertArrayHasKey('context', $capturedPayload);
        $this->assertSame('emails', $capturedPayload['context']['queue']);
    }

    /** @test */
    public function write_silently_catches_exceptions_and_never_throws(): void
    {
        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->andThrow(new \RuntimeException('Transport is down'));

        $this->handler->handle($this->makeRecord(Level::Critical, 'boom'));

        $this->assertTrue(true);
    }

    /** @test */
    public function write_uses_custom_channel_name(): void
    {
        $customChannels = new ChannelRegistry(['logs' => 'custom:logs']);
        $handler = new ZenithLogHandler(
            transport: $this->transport,
            channels: $customChannels,
        );

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with('custom:logs', Mockery::any());

        $handler->handle($this->makeRecord(Level::Info, 'test'));
        $this->addToAssertionCount(1);
    }

    /** @test */
    public function zenith_logger_creates_handler_with_injected_dependencies(): void
    {
        $factory = new ZenithLogger($this->transport, $this->channels);

        $logger = $factory(['level' => 'debug', 'bubble' => true]);

        $this->assertInstanceOf(Logger::class, $logger);
        $this->assertSame('zenith', $logger->getName());
        $this->assertNotEmpty($logger->getHandlers());
        $this->assertInstanceOf(ZenithLogHandler::class, $logger->getHandlers()[0]);
    }
}
