<?php

declare(strict_types=1);

namespace BlastRadius\Laravel\Console;

use BlastRadius\Impact\ImpactService;
use BlastRadius\Laravel\GraphBuilder;
use BlastRadius\Output\ImpactRenderer;
use Illuminate\Console\Command;
use Illuminate\Foundation\Application;
use Throwable;

final class ImpactCommand extends Command
{
    use ResolvesGraphOptions;

    protected $signature = 'blast
        {--base=main : Branch or commit to compare against}
        {--working-tree : Include uncommitted changes}
        {--depth=8 : How many calls deep to look for entry points}
        {--format=text : text, json or markdown}
        {--fail-on= : confirmed-untested exits 1 when a CONFIRMED entry point has no covering test}
        {--path= : Project root (default: the application base path)}
        {--paths= : Comma-separated folders to scan}
        {--db= : Graph cache file}';

    protected $description = 'Report what a change can break: affected entry points and the tests to run';

    public function handle(): int
    {
        $app = $this->laravel;

        if (! $app instanceof Application) {
            $this->error('blast needs a Laravel application.');

            return self::FAILURE;
        }

        [$root, $config, $db] = $this->graphOptions($app);

        $base = $this->option('base');
        $base = is_string($base) && $base !== '' ? $base : 'main';

        try {
            $built = (new GraphBuilder($app))->build($root, $config, $db);
            $report = ImpactService::forBase($root, $base, (bool) $this->option('working-tree'), $built->indexed, $built->all->nodes, $built->edges, max(1, (int) $this->option('depth')));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $format = $this->option('format');
        $this->getOutput()->write(ImpactRenderer::render($report, is_string($format) ? $format : 'text'));

        if ($this->option('fail-on') === 'confirmed-untested' && $report->confirmedUntested() !== []) {
            $this->error('CONFIRMED entry points with no covering test: '.implode(', ', $report->confirmedUntested()));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
