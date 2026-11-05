<?php

declare(strict_types=1);

namespace BlastRadius\Laravel\Console;

use BlastRadius\Coverage\CoverageRunner;
use BlastRadius\Coverage\CoverageService;
use BlastRadius\Coverage\TestDatabaseGuard;
use Illuminate\Console\Command;
use Illuminate\Foundation\Application;
use Throwable;

final class CoverageCommand extends Command
{
    use ResolvesGraphOptions;

    protected $signature = 'blast:coverage
        {--filter= : Only run tests matching this filter (keeps the run short)}
        {--tests=tests : Comma-separated test folders, relative to the root}
        {--phpunit= : Test runner to use (default: vendor/bin/phpunit)}
        {--path= : Project root (default: the application base path)}
        {--paths= : Comma-separated folders of code to analyse}
        {--db= : Graph cache file}
        {--force : Run even though no test database could be verified}';

    protected $description = 'Run the test suite once with a coverage driver and record which tests execute which methods';

    public function handle(): int
    {
        $app = $this->laravel;

        if (! $app instanceof Application) {
            $this->error('blast:coverage needs a Laravel application.');

            return self::FAILURE;
        }

        [$root, $config, $db] = $this->graphOptions($app);

        $this->warn('This runs your application\'s whole test suite. Tests can write to, or wipe, the database they use.');

        $guard = (new TestDatabaseGuard)->check($root);

        if (! $guard->safe) {
            $this->error($guard->reason);

            if (! $this->option('force')) {
                $this->line('Point the tests at a throwaway database (DB_DATABASE in phpunit.xml or .env.testing, e.g. :memory: or "myapp_test"),');
                $this->line('or pass --force if you are sure it is safe. Nothing was run.');

                return self::FAILURE;
            }

            $this->warn('--force given: running anyway.');
        } else {
            $this->line($guard->reason);
        }

        if (! CoverageRunner::driverAvailable()) {
            $this->error('No coverage driver found. Install and enable pcov (recommended) or Xdebug in coverage mode.');

            return self::FAILURE;
        }

        $runner = $this->option('phpunit');
        $runner = is_string($runner) && $runner !== '' ? $runner : $root.'/vendor/bin/phpunit';

        $tests = $this->option('tests');
        $testDirectories = array_map('trim', explode(',', is_string($tests) && $tests !== '' ? $tests : 'tests'));
        $filter = $this->option('filter');
        $arguments = is_string($filter) && $filter !== '' ? ['--filter='.$filter] : [];

        $this->line('Running the test suite with coverage; this can take a while...');

        try {
            $result = (new CoverageService($config, new CoverageRunner($runner)))->build($root, $db, $testDirectories, $arguments);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result['exitCode'] !== 0) {
            $this->warn('The test run reported failures (exit code '.$result['exitCode'].'); coverage was still recorded.');
        }

        $this->info(sprintf(
            '%d tests recorded; %d of %d methods were executed by at least one test.',
            $result['tests'],
            $result['coveredMethods'],
            $result['methods'],
        ));

        return self::SUCCESS;
    }
}
