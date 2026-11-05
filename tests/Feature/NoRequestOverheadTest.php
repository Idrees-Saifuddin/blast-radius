<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Feature;

use BlastRadius\Laravel\BlastRadiusServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class NoRequestOverheadTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [BlastRadiusServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // The browser UI is on, which is the worst case for the routes: its route is registered.
        // Tracing is switched off here; it records requests, which is the one thing it is for.
        $app['config']->set('blastradius.enabled', true);
        $app['config']->set('blastradius.ui.enabled', true);
        $app['config']->set('blastradius.runtime.enabled', false);
    }

    protected function defineRoutes($router): void
    {
        $router->get('/hello', fn () => 'hello');
    }

    /**
     * Runs in its own process so nothing else has loaded BlastRadius classes: after booting
     * the app, with the UI route registered, and serving some other URL, only the (tiny)
     * service provider may be loaded.
     */
    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    public function test_a_web_request_loads_nothing_but_the_service_provider(): void
    {
        $this->get('/hello')->assertOk()->assertSee('hello');

        $loaded = array_values(array_filter(
            array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits()),
            fn (string $class) => str_starts_with($class, 'BlastRadius\\') && ! str_starts_with($class, 'BlastRadius\\Tests\\'),
        ));

        $this->assertSame([BlastRadiusServiceProvider::class], $loaded);
    }
}
