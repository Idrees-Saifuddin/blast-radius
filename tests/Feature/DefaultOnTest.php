<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Feature;

use BlastRadius\Laravel\BlastRadiusServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;

/**
 * Installing the package is enough: no variable, no config.
 */
final class DefaultOnTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [BlastRadiusServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // the `web` middleware group needs an app key (every real app has one)
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    public function test_once_installed_it_runs_without_any_variable(): void
    {
        $this->assertNull(config('blastradius.enabled'), 'nothing is set: the package decides by the environment');

        foreach (['blast', 'blast:graph', 'blast:coverage', 'blast:report'] as $command) {
            $this->assertArrayHasKey($command, Artisan::all());
        }

        // the page exists; whether this address may open it is a separate question (see UiRouteTest)
        $this->assertNotSame(404, $this->get('/blast-radius')->getStatusCode());
    }

    public function test_the_switch_rule(): void
    {
        $this->assertTrue(BlastRadiusServiceProvider::enabled(null, production: false), 'on unless switched off');
        $this->assertTrue(BlastRadiusServiceProvider::enabled(true, production: false));
        $this->assertTrue(BlastRadiusServiceProvider::enabled('true', production: false));
        $this->assertTrue(BlastRadiusServiceProvider::enabled('1', production: false));

        $this->assertFalse(BlastRadiusServiceProvider::enabled(false, production: false));
        $this->assertFalse(BlastRadiusServiceProvider::enabled('false', production: false));
        $this->assertFalse(BlastRadiusServiceProvider::enabled('0', production: false));

        $this->assertFalse(BlastRadiusServiceProvider::enabled(null, production: true), 'unset in production: off');
        $this->assertTrue(BlastRadiusServiceProvider::enabled(true, production: true), 'true switches it on even in production');
        $this->assertTrue(BlastRadiusServiceProvider::enabled('true', production: true));
        $this->assertFalse(BlastRadiusServiceProvider::enabled(false, production: true));
        $this->assertFalse(BlastRadiusServiceProvider::enabled('false', production: true));
    }

    public function test_the_ui_and_tracing_follow_the_switch_unless_turned_off(): void
    {
        $this->assertTrue(BlastRadiusServiceProvider::uiEnabled(null));
        $this->assertTrue(BlastRadiusServiceProvider::uiEnabled(true));
        $this->assertFalse(BlastRadiusServiceProvider::uiEnabled(false));
        $this->assertFalse(BlastRadiusServiceProvider::uiEnabled('false'));

        $this->assertTrue(BlastRadiusServiceProvider::tracingEnabled(null, uiEnabled: true));
        $this->assertFalse(BlastRadiusServiceProvider::tracingEnabled(null, uiEnabled: false));
        $this->assertFalse(BlastRadiusServiceProvider::tracingEnabled(false, uiEnabled: true));
        $this->assertTrue(BlastRadiusServiceProvider::tracingEnabled('true', uiEnabled: false));
    }
}
