<?php

declare(strict_types=1);

namespace BlastRadius\Static;

use BlastRadius\Core\Edge;
use BlastRadius\Core\Node;

final class SymbolTable
{
    /** @var array<string, Node> */
    private array $byName = [];

    /**
     * @param  list<Node>  $nodes
     * @param  array<string, string>  $errors  file => parse error message
     * @param  list<Edge>  $edges
     * @param  array<string, array<string, string>>  $propertyTypes  class => property => declared class type
     * @param  IndexStats|null  $stats  what the indexer did to produce this table
     */
    public function __construct(
        public readonly array $nodes,
        public readonly array $errors = [],
        public readonly array $edges = [],
        public readonly array $propertyTypes = [],
        public readonly ?IndexStats $stats = null,
    ) {
        foreach ($nodes as $node) {
            $this->byName[$node->name] = $node;
        }
    }

    public function find(string $name): ?Node
    {
        return $this->byName[$name] ?? null;
    }
}
