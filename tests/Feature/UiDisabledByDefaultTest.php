<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Feature;

use BlastRadius\Laravel\BlastRadiusServiceProvider;
use BlastRadius\Laravel\UiAccess;
use Orchestra\Testbench\TestCase;

final class UiDisabledByDefaultTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [BlastRadiusServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // The package is on, but the UI itself is turned off.
        $app['config']->set('blastradius.enabled', true);
        $app['config']->set('blastradius.ui.enabled', false);
    }

    protected function tearDown(): void
    {
        UiAccess::reset();

        parent::tearDown();
    }

    public function test_the_route_does_not_exist_when_the_ui_is_turned_off(): void
    {
        UiAccess::using(fn () => true);

        $this->get('/blast-radius')->assertNotFound();
    }
}
