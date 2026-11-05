<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Feature;

use BlastRadius\Laravel\BlastRadiusServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;

/**
 * A production server where nobody set BLASTRADIUS_ENABLED must not run the package.
 */
final class DisabledInProductionTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [BlastRadiusServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['env'] = 'production';
    }

    public function test_in_production_with_the_variable_unset_nothing_is_registered(): void
    {
        $this->assertNotNull($this->app);
        $this->assertTrue($this->app->isProduction());
        $this->assertNull(config('blastradius.enabled'));

        foreach (['blast', 'blast:graph', 'blast:coverage', 'blast:report'] as $command) {
            $this->assertArrayNotHasKey($command, Artisan::all());
        }

        $this->get('/blast-radius')->assertNotFound();
    }
}
