<?php

declare(strict_types=1);

namespace BlastRadius\Laravel;

use BlastRadius\Laravel\Console\CoverageCommand;
use BlastRadius\Laravel\Console\GraphCommand;
use BlastRadius\Laravel\Console\ImpactCommand;
use BlastRadius\Laravel\Console\ReportCommand;
use BlastRadius\Laravel\Http\Authorize;
use BlastRadius\Laravel\Http\RecordRequest;
use BlastRadius\Runtime\Database\Tracing;
use BlastRadius\Runtime\InstrumentingLoader;
use BlastRadius\Runtime\RequestRecorder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * On once installed, off with BLASTRADIUS_ENABLED=false. In production it stays off unless
 * BLASTRADIUS_ENABLED=true says otherwise. When off it registers nothing: no commands, no
 * routes, no middleware, no listeners.
 */
final class BlastRadiusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/blastradius.php', 'blastradius');
    }

    public function boot(): void
    {
        if (! self::enabled(config('blastradius.enabled'), $this->app->environment('production') === true)) {
            return;
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../../config/blastradius.php' => config_path('blastradius.php')], 'blastradius-config');

            $this->commands([GraphCommand::class, CoverageCommand::class, ImpactCommand::class, ReportCommand::class]);
        }

        $ui = self::uiEnabled(config('blastradius.ui.enabled'));

        if (! $this->app->runningInConsole() && self::tracingEnabled(config('blastradius.runtime.enabled'), $ui)) {
            $this->startTracing();
        }

        if ($ui) {
            Route::group([
                'prefix' => trim((string) config('blastradius.ui.path', 'blast-radius'), '/'),
                'middleware' => [...array_values((array) config('blastradius.ui.middleware', ['web'])), Authorize::class],
            ], fn () => $this->loadRoutesFrom(__DIR__.'/../../routes/blastradius.php'));
        }
    }

    /**
     * An explicit BLASTRADIUS_ENABLED wins in every environment. Left unset the package is on,
     * except in production, so a server where nobody asked for it never runs it.
     */
    public static function enabled(mixed $setting, bool $production): bool
    {
        return $setting === null ? ! $production : filter_var($setting, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The browser UI follows the switch unless it is turned off explicitly.
     */
    public static function uiEnabled(mixed $setting): bool
    {
        return $setting === null ? true : filter_var($setting, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Live tracing follows the UI unless it is set explicitly.
     */
    public static function tracingEnabled(mixed $setting, bool $uiEnabled): bool
    {
        return $setting === null ? $uiEnabled : filter_var($setting, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Time the application's functions, count query rows, and record every request. Only
     * reached only when the package is enabled.
     */
    private function startTracing(): void
    {
        InstrumentingLoader::register(
            GraphSettings::root($this->app),
            array_values(array_map('strval', (array) config('blastradius.runtime.paths', ['app']))),
            GraphSettings::compiledPath($this->app),
        );
        Tracing::register();

        $events = $this->app->make('events');
        $events->listen(QueryExecuted::class, [RequestRecorder::class, 'query']);
        $events->listen('eloquent.retrieved: *', [RequestRecorder::class, 'model']);

        $kernel = $this->app->make(Kernel::class);

        if (method_exists($kernel, 'prependMiddleware')) {
            $kernel->prependMiddleware(RecordRequest::class);
        }
    }
}
