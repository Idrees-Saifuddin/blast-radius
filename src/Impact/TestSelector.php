<?php

declare(strict_types=1);

namespace BlastRadius\Impact;

use BlastRadius\Core\Edge;
use BlastRadius\Core\EdgeKind;
use BlastRadius\Core\Node;
use BlastRadius\Core\NodeKind;

final class TestSelector
{
    /**
     * The tests that actually executed at least one of the changed methods, from the coverage map.
     *
     * @param  list<Node>  $nodes
     * @param  list<Edge>  $edges
     * @param  list<string>  $changed  method names, `Fqcn::method`
     * @return array<string, string> test => test file, sorted by test
     */
    public static function select(array $nodes, array $edges, array $changed): array
    {
        $changed = array_flip($changed);
        $tests = [];

        foreach ($edges as $edge) {
            if ($edge->kind === EdgeKind::Covers && isset($changed[$edge->to])) {
                $tests[$edge->from] = true;
            }
        }

        $files = [];

        foreach ($nodes as $node) {
            if ($node->kind === NodeKind::Test && isset($tests[$node->name])) {
                $files[$node->name] = $node->file;
            }
        }

        ksort($files);

        return $files;
    }
}
