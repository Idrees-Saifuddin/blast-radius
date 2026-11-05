<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Feature;

use BlastRadius\Laravel\BlastRadiusServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;

final class DisabledByEnvironmentTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [BlastRadiusServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('blastradius.enabled', false);
    }

    public function test_with_the_variable_set_to_false_nothing_is_registered(): void
    {
        $this->assertArrayNotHasKey('blast:graph', Artisan::all());
        $this->get('/blast-radius')->assertNotFound();
    }
}
