<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Feature;

use BlastRadius\Laravel\BlastRadiusServiceProvider;
use BlastRadius\Laravel\UiAccess;
use Orchestra\Testbench\TestCase;

final class UiDisabledByMasterSwitchTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [BlastRadiusServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('blastradius.enabled', false);
        $app['config']->set('blastradius.ui.enabled', true);
    }

    protected function tearDown(): void
    {
        UiAccess::reset();

        parent::tearDown();
    }

    public function test_blastradius_enabled_false_turns_the_ui_off_too(): void
    {
        UiAccess::using(fn () => true);

        $this->get('/blast-radius')->assertNotFound();
    }
}
