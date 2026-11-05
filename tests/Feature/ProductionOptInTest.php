<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Feature;

use BlastRadius\Laravel\BlastRadiusServiceProvider;
use BlastRadius\Laravel\UiAccess;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;

/**
 * Checking production on purpose: BLASTRADIUS_ENABLED=true works there, and the page still
 * refuses public addresses unless UiAccess says otherwise.
 */
final class ProductionOptInTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [BlastRadiusServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['env'] = 'production';
        $app['config']->set('blastradius.enabled', true);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    protected function tearDown(): void
    {
        UiAccess::reset();

        parent::tearDown();
    }

    public function test_the_variable_set_to_true_switches_it_on_in_production(): void
    {
        $this->assertNotNull($this->app);
        $this->assertTrue($this->app->isProduction());

        foreach (['blast', 'blast:graph', 'blast:coverage', 'blast:report'] as $command) {
            $this->assertArrayHasKey($command, Artisan::all());
        }

        $this->assertNotSame(404, $this->get('/blast-radius')->getStatusCode(), 'the page exists');
    }

    public function test_in_production_the_page_still_refuses_public_addresses_by_default(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get('/blast-radius')->assertForbidden();

        UiAccess::using(fn () => true);
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get('/blast-radius')->assertOk();
    }
}
