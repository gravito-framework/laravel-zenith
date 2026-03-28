<?php

namespace Gravito\Zenith\Laravel\Tests\Unit;

use Gravito\Zenith\Laravel\Support\ConfigValidator;
use Gravito\Zenith\Laravel\Tests\TestCase;
use InvalidArgumentException;

class ConfigValidatorTest extends TestCase
{
    /**
     * Build a complete valid config and set it in the app config.
     */
    protected function setValidConfig(array $overrides = []): void
    {
        $config = array_replace_recursive([
            'enabled'   => true,
            'transport' => [
                'driver' => 'redis',
                'connection' => 'default',
            ],
            'http'      => [
                'slow_threshold' => 1000,
                'ignore_paths'   => ['/health', '/nova*'],
            ],
            'heartbeat' => [
                'interval' => 5,
                'ttl'      => 30,
            ],
            'queues'    => [
                'ignore_jobs' => [],
            ],
        ], $overrides);

        config(['zenith' => $config]);
    }

    /**
     * @test
     */
    public function valid_config_passes_validation(): void
    {
        $this->setValidConfig();

        // Should not throw any exception
        ConfigValidator::validate();

        $this->assertTrue(true);
    }

    /**
     * @test
     */
    public function negative_slow_threshold_throws_invalid_argument_exception(): void
    {
        $this->setValidConfig(['http' => ['slow_threshold' => -1]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('zenith.http.slow_threshold must be a non-negative number.');

        ConfigValidator::validate();
    }

    /**
     * @test
     */
    public function non_numeric_slow_threshold_throws_invalid_argument_exception(): void
    {
        $this->setValidConfig(['http' => ['slow_threshold' => 'fast']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('zenith.http.slow_threshold must be a non-negative number.');

        ConfigValidator::validate();
    }

    /**
     * @test
     */
    public function zero_heartbeat_interval_throws_invalid_argument_exception(): void
    {
        $this->setValidConfig(['heartbeat' => ['interval' => 0, 'ttl' => 30]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('zenith.heartbeat.interval must be a positive number.');

        ConfigValidator::validate();
    }

    /**
     * @test
     */
    public function ttl_less_than_interval_throws_invalid_argument_exception(): void
    {
        $this->setValidConfig(['heartbeat' => ['interval' => 30, 'ttl' => 10]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('zenith.heartbeat.ttl should be >= interval to avoid stale worker keys.');

        ConfigValidator::validate();
    }

    /**
     * @test
     */
    public function non_string_items_in_ignore_jobs_throws_invalid_argument_exception(): void
    {
        $this->setValidConfig(['queues' => ['ignore_jobs' => [123, 'App\Jobs\ValidJob']]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('zenith.queues.ignore_jobs must contain only strings.');

        ConfigValidator::validate();
    }

    /**
     * @test
     */
    public function non_string_items_in_ignore_paths_throws_invalid_argument_exception(): void
    {
        $this->setValidConfig(['http' => ['slow_threshold' => 1000, 'ignore_paths' => ['/health', 42]]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('zenith.http.ignore_paths must contain only strings.');

        ConfigValidator::validate();
    }

    /**
     * @test
     */
    public function non_array_ignore_paths_throws_invalid_argument_exception(): void
    {
        $this->setValidConfig(['http' => ['slow_threshold' => 1000, 'ignore_paths' => '/health']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('zenith.http.ignore_paths must be an array.');

        ConfigValidator::validate();
    }

    /** @test */
    public function non_string_transport_driver_throws_invalid_argument_exception(): void
    {
        $this->setValidConfig(['transport' => ['driver' => 123]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('zenith.transport.driver must be a string.');

        ConfigValidator::validate();
    }

    /** @test */
    public function empty_transport_driver_throws_invalid_argument_exception(): void
    {
        $this->setValidConfig(['transport' => ['driver' => '']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('zenith.transport.driver must be a non-empty string.');

        ConfigValidator::validate();
    }

    /** @test */
    public function valid_config_with_transport_section_passes(): void
    {
        $this->setValidConfig([
            'transport' => ['driver' => 'redis', 'connection' => 'default'],
        ]);

        ConfigValidator::validate();

        $this->assertTrue(true);
    }
}
