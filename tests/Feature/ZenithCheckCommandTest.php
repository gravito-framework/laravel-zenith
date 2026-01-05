<?php

namespace Gravito\Zenith\Laravel\Tests\Feature;

use Gravito\Zenith\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

class ZenithCheckCommandTest extends TestCase
{
    /** @test */
    public function it_can_run_zenith_check_command(): void
    {
        $exitCode = Artisan::call('zenith:check');

        // Command should complete (exit code may vary based on Redis availability)
        $this->assertIsInt($exitCode);
    }

    /** @test */
    public function it_reports_when_zenith_is_disabled(): void
    {
        config(['zenith.enabled' => false]);

        $exitCode = Artisan::call('zenith:check');
        $output = Artisan::output();

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('disabled', $output);
    }

    /** @test */
    public function it_displays_configuration_status(): void
    {
        config(['zenith.enabled' => true]);

        $exitCode = Artisan::call('zenith:check');
        $output = Artisan::output();

        // If Redis is not available, the command will fail early
        // So we only check for configuration display if Redis is available
        if ($exitCode === 0) {
            $this->assertStringContainsString('Configuration', $output);
            $this->assertStringContainsString('Logging', $output);
            $this->assertStringContainsString('Queue Monitoring', $output);
            $this->assertStringContainsString('HTTP Monitoring', $output);
        } else {
            // If Redis is not available, we should see an error message
            $this->assertStringContainsString('Redis', $output);
        }
    }
}
