<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Feature;

use BlastRadius\Laravel\BlastRadiusServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;

final class EnabledTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [BlastRadiusServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('blastradius.enabled', true);
    }

    public function test_explicitly_true_outside_production_the_commands_exist(): void
    {
        foreach (['blast', 'blast:graph', 'blast:coverage', 'blast:report'] as $command) {
            $this->assertArrayHasKey($command, Artisan::all());
        }
    }
}
