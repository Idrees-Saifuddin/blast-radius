<?php

declare(strict_types=1);

namespace BlastRadius\Laravel;

use BlastRadius\Core\Edge;
use BlastRadius\Core\Node;

final class AppGraph
{
    /**
     * @param  list<Node>  $nodes  entry points and closures found in the booted app
     * @param  list<Edge>  $edges
     * @param  array<string, string>  $bindings  abstract => concrete class
     * @param  array<string, string>  $facades  facade class => concrete class
     * @param  list<string>  $warnings  parts of the app that could not be read
     */
    public function __construct(
        public readonly array $nodes,
        public readonly array $edges,
        public readonly array $bindings,
        public readonly array $facades,
        public readonly array $warnings,
    ) {}
}
