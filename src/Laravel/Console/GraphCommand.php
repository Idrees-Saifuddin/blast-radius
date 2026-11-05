<?php

declare(strict_types=1);

namespace BlastRadius\Laravel\Console;

use BlastRadius\Core\Confidence;
use BlastRadius\Impact\Traversal;
use BlastRadius\Laravel\GraphBuilder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Application;

final class GraphCommand extends Command
{
    use ResolvesGraphOptions;

    protected $signature = 'blast:graph
        {--path= : Project root (default: the application base path)}
        {--paths= : Comma-separated folders to scan, relative to the root}
        {--db= : Graph cache file}
        {--depth=4 : How many calls deep to follow from each entry point}';

    protected $description = 'List routes, jobs, listeners, commands and schedule entries with the methods they reach';

    public function handle(): int
    {
        $app = $this->laravel;

        if (! $app instanceof Application) {
            $this->error('blast:graph needs a Laravel application.');

            return self::FAILURE;
        }

        [$root, $config, $db] = $this->graphOptions($app);

        $built = (new GraphBuilder($app))->build($root, $config, $db);
        $graph = $built->graph;
        $table = $built->indexed;
        $edges = $built->edges;
        $depth = max(1, (int) $this->option('depth'));

        $entries = array_filter($graph->nodes, fn ($node) => $node->kind->isEntryPoint());
        usort($entries, fn ($a, $b) => [$a->kind->value, $a->name] <=> [$b->kind->value, $b->name]);

        $this->line(sprintf('%d entry points, %d bindings, %d facades.', count($entries), count($graph->bindings), count($graph->facades)));

        foreach ($entries as $entry) {
            $this->newLine();
            $this->line("<options=bold>{$entry->name}</>");

            $reached = Traversal::forward($edges, $entry->name, $depth);

            if ($reached === []) {
                $this->line('  (reaches nothing in the scanned code)');
            }

            foreach ($reached as $name => $info) {
                $this->line(sprintf('  %-10s depth %d  %s', $info['confidence']->value, $info['depth'], $name));
            }
        }

        foreach ($table->errors as $file => $error) {
            $this->warn("Could not parse {$file}: {$error}");
        }

        foreach ($graph->warnings as $warning) {
            $this->warn("Could not read {$warning}");
        }

        $unresolved = count(array_filter($edges, fn ($e) => $e->confidence === Confidence::Unresolved));

        if ($unresolved > 0) {
            $this->newLine();
            $this->line("{$unresolved} UNRESOLVED edges (dynamic calls) are not shown above.");
        }

        return self::SUCCESS;
    }
}
