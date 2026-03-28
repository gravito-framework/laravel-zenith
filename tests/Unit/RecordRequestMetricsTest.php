<?php

namespace Gravito\Zenith\Laravel\Tests\Unit;

use Closure;
use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Gravito\Zenith\Laravel\Http\Middleware\RecordRequestMetrics;
use Gravito\Zenith\Laravel\Support\ChannelRegistry;
use Gravito\Zenith\Laravel\Tests\TestCase;
use Illuminate\Http\Request;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\Response;

class RecordRequestMetricsTest extends TestCase
{
    protected MockInterface $transport;
    protected ChannelRegistry $channels;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = Mockery::mock(TransportInterface::class);
        $this->channels = new ChannelRegistry([]);

        config([
            'zenith.enabled' => true,
            'zenith.http.enabled' => true,
            'zenith.http.ignore_paths' => [],
            'zenith.http.slow_threshold' => 1000,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeMiddleware(): RecordRequestMetrics
    {
        return new RecordRequestMetrics($this->transport, $this->channels);
    }

    private function makeRequest(string $path = 'api/test', string $method = 'GET'): Request
    {
        return Request::create('/' . ltrim($path, '/'), $method);
    }

    private function makeResponse(int $status = 200): Response
    {
        return new Response('', $status);
    }

    private function nextReturning(Response $response): Closure
    {
        return fn (Request $request) => $response;
    }

    /** @test */
    public function it_passes_request_through_when_zenith_is_disabled(): void
    {
        config(['zenith.enabled' => false]);

        $this->transport->shouldReceive('publish')->never();

        $request = $this->makeRequest();
        $response = $this->makeResponse(200);
        $middleware = $this->makeMiddleware();

        $result = $middleware->handle($request, $this->nextReturning($response));

        $this->assertSame($response, $result);
    }

    /** @test */
    public function it_passes_request_through_when_http_tracking_is_disabled(): void
    {
        config(['zenith.http.enabled' => false]);

        $this->transport->shouldReceive('publish')->never();

        $request = $this->makeRequest();
        $response = $this->makeResponse(200);
        $middleware = $this->makeMiddleware();

        $result = $middleware->handle($request, $this->nextReturning($response));

        $this->assertSame($response, $result);
    }

    /** @test */
    public function it_skips_ignored_paths(): void
    {
        config(['zenith.http.ignore_paths' => ['/nova*']]);

        $this->transport->shouldReceive('publish')->never();

        $request = $this->makeRequest('nova/resources/users');
        $response = $this->makeResponse(200);
        $middleware = $this->makeMiddleware();

        $result = $middleware->handle($request, $this->nextReturning($response));

        $this->assertSame($response, $result);
    }

    /** @test */
    public function it_does_not_log_normal_fast_requests_below_slow_threshold(): void
    {
        config(['zenith.http.slow_threshold' => 1000]);

        $this->transport->shouldReceive('publish')->never();
        $this->transport->shouldReceive('increment')->never();

        $request = $this->makeRequest('api/users');
        $response = $this->makeResponse(200);
        $middleware = $this->makeMiddleware();

        $middleware->handle($request, $this->nextReturning($response));

        $this->assertTrue(true);
    }

    /** @test */
    public function it_logs_slow_requests_above_slow_threshold(): void
    {
        config(['zenith.http.slow_threshold' => 0]);

        $publishedPayload = null;

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(function (array $payload) use (&$publishedPayload) {
                    $publishedPayload = $payload;
                    return true;
                })
            );

        $this->transport->shouldReceive('increment')->twice();

        $request = $this->makeRequest('api/users', 'GET');
        $response = $this->makeResponse(200);
        $middleware = $this->makeMiddleware();

        $middleware->handle($request, $this->nextReturning($response));

        $this->assertNotNull($publishedPayload);
        $this->assertSame('warn', $publishedPayload['level']);
        $this->assertStringContainsString('Slow Request', $publishedPayload['message']);
        $this->assertSame('GET', $publishedPayload['context']['method']);
        $this->assertSame(200, $publishedPayload['context']['status']);
    }

    /** @test */
    public function it_logs_5xx_error_responses(): void
    {
        $publishedPayload = null;

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(function (array $payload) use (&$publishedPayload) {
                    $publishedPayload = $payload;
                    return true;
                })
            );

        $this->transport->shouldReceive('increment')->once();

        $request = $this->makeRequest('api/endpoint');
        $response = $this->makeResponse(500);
        $middleware = $this->makeMiddleware();

        $middleware->handle($request, $this->nextReturning($response));

        $this->assertNotNull($publishedPayload);
        $this->assertSame('error', $publishedPayload['level']);
        $this->assertSame(500, $publishedPayload['context']['status']);
        $this->assertStringContainsString('HTTP 500', $publishedPayload['message']);
    }

    /** @test */
    public function it_logs_4xx_client_error_responses(): void
    {
        $publishedPayload = null;

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(function (array $payload) use (&$publishedPayload) {
                    $publishedPayload = $payload;
                    return true;
                })
            );

        $this->transport->shouldReceive('increment')->once();

        $request = $this->makeRequest('api/missing');
        $response = $this->makeResponse(404);
        $middleware = $this->makeMiddleware();

        $middleware->handle($request, $this->nextReturning($response));

        $this->assertNotNull($publishedPayload);
        $this->assertSame('warn', $publishedPayload['level']);
        $this->assertSame(404, $publishedPayload['context']['status']);
        $this->assertStringContainsString('HTTP 404', $publishedPayload['message']);
    }

    /** @test */
    public function determine_level_returns_correct_levels(): void
    {
        $middleware = $this->makeMiddleware();
        $method = new \ReflectionMethod($middleware, 'determineLevel');
        $method->setAccessible(true);

        $this->assertSame('error', $method->invoke($middleware, 500, 10));
        $this->assertSame('warn', $method->invoke($middleware, 404, 10));
        $this->assertSame('warn', $method->invoke($middleware, 200, 1001));
        $this->assertSame('info', $method->invoke($middleware, 200, 100));
    }

    /** @test */
    public function published_payload_contains_all_required_fields(): void
    {
        config(['zenith.http.slow_threshold' => 0]);

        $publishedPayload = null;

        $this->transport
            ->shouldReceive('publish')
            ->once()
            ->with(
                'zenith:logs',
                Mockery::on(function (array $payload) use (&$publishedPayload) {
                    $publishedPayload = $payload;
                    return true;
                })
            );

        $this->transport->shouldReceive('increment')->twice();

        $request = $this->makeRequest('api/orders', 'POST');
        $response = $this->makeResponse(200);
        $middleware = $this->makeMiddleware();

        $middleware->handle($request, $this->nextReturning($response));

        $this->assertArrayHasKey('level', $publishedPayload);
        $this->assertArrayHasKey('message', $publishedPayload);
        $this->assertArrayHasKey('workerId', $publishedPayload);
        $this->assertArrayHasKey('timestamp', $publishedPayload);
        $this->assertArrayHasKey('context', $publishedPayload);

        $context = $publishedPayload['context'];
        $this->assertArrayHasKey('method', $context);
        $this->assertArrayHasKey('path', $context);
        $this->assertArrayHasKey('status', $context);
        $this->assertArrayHasKey('duration', $context);
        $this->assertArrayHasKey('route', $context);

        $this->assertSame('POST', $context['method']);
        $this->assertStringEndsWith('-http', $publishedPayload['workerId']);
    }

    /** @test */
    public function should_ignore_path_normalises_leading_slash(): void
    {
        config(['zenith.http.ignore_paths' => ['/health']]);

        $middleware = $this->makeMiddleware();
        $method = new \ReflectionMethod($middleware, 'shouldIgnorePath');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($middleware, 'health'));
        $this->assertTrue($method->invoke($middleware, '/health'));
    }
}
