<?php

declare(strict_types=1);

namespace BlastRadius\Laravel\Console;

use BlastRadius\Impact\ImpactService;
use BlastRadius\Laravel\GraphBuilder;
use BlastRadius\Output\HtmlReport;
use BlastRadius\Output\ReportData;
use Illuminate\Console\Command;
use Illuminate\Foundation\Application;
use Throwable;

final class ReportCommand extends Command
{
    use ResolvesGraphOptions;

    protected $signature = 'blast:report
        {--out=blast-report.html : File to write, relative to the project root}
        {--depth=6 : How many calls deep to draw from each entry point}
        {--base= : Also highlight what changed since this branch or commit}
        {--working-tree : With --base: include uncommitted changes}
        {--path= : Project root (default: the application base path)}
        {--paths= : Comma-separated folders to scan}
        {--db= : Graph cache file}';

    protected $description = 'Write a self-contained HTML report: every entry point as a flow diagram of the code it reaches';

    public function handle(): int
    {
        $app = $this->laravel;

        if (! $app instanceof Application) {
            $this->error('blast:report needs a Laravel application.');

            return self::FAILURE;
        }

        [$root, $config, $db] = $this->graphOptions($app);
        $depth = max(1, (int) $this->option('depth'));

        $base = $this->option('base');
        $base = is_string($base) && $base !== '' ? $base : null;

        try {
            $built = (new GraphBuilder($app))->build($root, $config, $db);
            $impact = $base === null ? null : ImpactService::forBase($root, $base, (bool) $this->option('working-tree'), $built->indexed, $built->all->nodes, $built->edges, 8);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $out = $this->option('out');
        $out = is_string($out) && $out !== '' ? $out : 'blast-report.html';
        $file = str_starts_with($out, '/') ? $out : $root.'/'.$out;

        if (! is_dir(dirname($file)) && ! mkdir(dirname($file), 0777, true)) {
            $this->error('Could not create '.dirname($file));

            return self::FAILURE;
        }

        file_put_contents($file, HtmlReport::render(ReportData::build($root, $built->all->nodes, $built->edges, $depth, $impact, $base, false, (string) config('app.name'))));

        $this->info(sprintf('Report written to %s (%s KB). Open it in a browser.', $file, number_format((int) (filesize($file) / 1024))));

        return self::SUCCESS;
    }
}
