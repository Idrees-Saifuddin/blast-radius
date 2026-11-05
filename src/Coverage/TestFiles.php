<?php

declare(strict_types=1);

namespace BlastRadius\Coverage;

use BlastRadius\Core\Config;
use BlastRadius\Static\SymbolIndexer;

final class TestFiles
{
    /**
     * @param  list<string>  $directories  test folders relative to the root
     * @return array<string, string> test class => file
     */
    public static function map(string $root, array $directories): array
    {
        $map = [];

        foreach ((new SymbolIndexer(new Config(paths: $directories)))->index($root)->nodes as $node) {
            if ($node->kind->isClassLike()) {
                $map[$node->name] = $node->file;
            }
        }

        return $map;
    }
}
