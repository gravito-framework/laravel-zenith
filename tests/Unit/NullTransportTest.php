<?php

namespace Gravito\Zenith\Laravel\Tests\Unit;

use Gravito\Zenith\Laravel\Contracts\TransportInterface;
use Gravito\Zenith\Laravel\Transport\NullTransport;
use Gravito\Zenith\Laravel\Tests\TestCase;

class NullTransportTest extends TestCase
{
    protected NullTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new NullTransport();
    }

    /** @test */
    public function it_implements_transport_interface(): void
    {
        $this->assertInstanceOf(TransportInterface::class, $this->transport);
    }

    /** @test */
    public function publish_does_nothing_without_error(): void
    {
        $this->transport->publish('test-topic', ['key' => 'value']);
        $this->assertTrue(true);
    }

    /** @test */
    public function store_does_nothing_without_error(): void
    {
        $this->transport->store('test-key', ['data' => 'value'], 60);
        $this->assertTrue(true);
    }

    /** @test */
    public function increment_does_nothing_without_error(): void
    {
        $this->transport->increment('test-counter', 3600);
        $this->assertTrue(true);
    }

    /** @test */
    public function ping_returns_true(): void
    {
        $this->assertTrue($this->transport->ping());
    }
}
