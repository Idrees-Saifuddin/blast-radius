<?php

declare(strict_types=1);

namespace BlastRadius\Laravel;

use BlastRadius\Core\Config;
use BlastRadius\Static\GraphStore;
use BlastRadius\Static\Hierarchy;
use BlastRadius\Static\SymbolIndexer;
use Illuminate\Foundation\Application;

/**
 * The shared pipeline behind every command: scan the code (incrementally), read the booted
 * app, store its layer, and apply container bindings to get the effective edges.
 */
final class GraphBuilder
{
    public function __construct(private readonly Application $app) {}

    public function build(string $root, Config $config, string $db): BuiltGraph
    {
        $indexed = (new SymbolIndexer($config, $db))->index($root);
        $hierarchy = new Hierarchy($indexed);
        $graph = (new AppIntrospector($this->app, $hierarchy, $root))->introspect();

        $store = new GraphStore($db);
        $store->replaceLayer('app', $graph->nodes, $graph->edges);
        $all = $store->table();

        return new BuiltGraph(
            $root,
            $db,
            $indexed,
            $all,
            $hierarchy,
            $graph,
            BindingUpgrader::apply($all->edges, $graph->bindings, $graph->facades, $hierarchy),
        );
    }
}
