<?php

declare(strict_types=1);

namespace BlastRadius\Laravel;

use BlastRadius\Core\Edge;
use BlastRadius\Static\Hierarchy;
use BlastRadius\Static\SymbolTable;

final class BuiltGraph
{
    /**
     * @param  SymbolTable  $indexed  what the code scan found (no app or coverage layers)
     * @param  SymbolTable  $all  everything in the cache, including entry points and coverage
     * @param  list<Edge>  $edges  effective edges: bindings and facades already applied
     */
    public function __construct(
        public readonly string $root,
        public readonly string $db,
        public readonly SymbolTable $indexed,
        public readonly SymbolTable $all,
        public readonly Hierarchy $hierarchy,
        public readonly AppGraph $graph,
        public readonly array $edges,
    ) {}
}
