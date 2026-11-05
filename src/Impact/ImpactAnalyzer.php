<?php

declare(strict_types=1);

namespace BlastRadius\Impact;

use BlastRadius\Core\Confidence;
use BlastRadius\Core\Edge;
use BlastRadius\Core\EdgeKind;
use BlastRadius\Core\Node;

final class ImpactAnalyzer
{
    // Plain strings: `Enum::Case->value` is not allowed in a constant before PHP 8.2. A test keeps these in step with Confidence.
    private const RANK = ['CONFIRMED' => 2, 'POSSIBLE' => 1, 'UNRESOLVED' => 0];

    /**
     * Walks the call graph backwards from the changed symbols to the entry points that can
     * reach them. Each entry is reported once, by its shortest path; the confidence of a path
     * is its weakest edge.
     *
     * @param  list<Change>  $changes
     * @param  list<Node>  $nodes
     * @param  list<Edge>  $edges  effective edges (bindings already applied)
     * @param  list<string>  $files  every file the diff touched, kept for the report
     */
    public static function analyze(array $changes, array $nodes, array $edges, int $maxDepth = 8, array $files = []): ImpactReport
    {
        $incoming = [];

        foreach ($edges as $edge) {
            if (! $edge->kind->isHierarchy() && $edge->kind !== EdgeKind::Covers) {
                $incoming[$edge->to][] = $edge;
            }
        }

        $entryKinds = [];

        foreach ($nodes as $node) {
            if ($node->kind->isEntryPoint()) {
                $entryKinds[$node->name] = $node->kind->value;
            }
        }

        $queue = [];
        $seen = [];

        foreach ($changes as $change) {
            if ($change->kind !== Change::ADDED) {
                $queue[] = [$change->symbol, 0, Confidence::Confirmed, [$change->symbol]];
                $seen[$change->symbol] = true;
            }
        }

        $entries = [];

        while ($queue !== []) {
            [$current, $depth, $confidence, $path] = array_shift($queue);

            if (isset($entryKinds[$current])) {
                $entries[$current] = ['entry' => $current, 'kind' => $entryKinds[$current], 'depth' => $depth, 'confidence' => $confidence, 'path' => array_reverse($path), 'tested' => false];
            }

            if ($depth >= $maxDepth) {
                continue;
            }

            foreach ($incoming[$current] ?? [] as $edge) {
                if (isset($seen[$edge->from])) {
                    continue;
                }

                $seen[$edge->from] = true;
                $weakest = self::RANK[$confidence->value] <= self::RANK[$edge->confidence->value] ? $confidence : $edge->confidence;
                $queue[] = [$edge->from, $depth + 1, $weakest, [...$path, $edge->from]];
            }
        }

        $changed = array_values(array_map(fn (Change $c) => $c->symbol, array_filter($changes, fn (Change $c) => $c->kind !== Change::ADDED)));
        $tests = TestSelector::select($nodes, $edges, $changed);

        foreach ($entries as &$entry) {
            // Tested when some test executed a method on the path from the change to the entry point.
            $entry['tested'] = TestSelector::select($nodes, $edges, $entry['path']) !== [];
        }

        unset($entry);

        uasort($entries, fn (array $a, array $b) => [-self::RANK[$a['confidence']->value], $a['depth'], $a['entry']] <=> [-self::RANK[$b['confidence']->value], $b['depth'], $b['entry']]);

        return new ImpactReport($changes, array_values($entries), $tests, $files);
    }
}
