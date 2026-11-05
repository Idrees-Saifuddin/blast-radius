<?php

declare(strict_types=1);

namespace BlastRadius\Output;

use BlastRadius\Core\Confidence;
use BlastRadius\Core\Edge;
use BlastRadius\Core\EdgeKind;
use BlastRadius\Core\Node;
use BlastRadius\Core\NodeKind;
use BlastRadius\Impact\ImpactReport;

/**
 * Turns the graph into the plain data the HTML report draws: for every entry point, the
 * functions it reaches (breadth first, limited in depth) and the links between them.
 */
final class ReportData
{
    private const MAX_NODES_PER_ENTRY = 250;

    // Plain strings: `Enum::Case->value` is not allowed in a constant before PHP 8.2. A test keeps these in step with Confidence.
    private const RANK = ['CONFIRMED' => 2, 'POSSIBLE' => 1, 'UNRESOLVED' => 0];

    /**
     * @param  list<Node>  $nodes  every node: code symbols, entry points, tests
     * @param  list<Edge>  $edges  effective edges
     * @return array<string, mixed>
     */
    public static function build(string $root, array $nodes, array $edges, int $depth, ?ImpactReport $impact = null, ?string $base = null, bool $live = false, ?string $project = null): array
    {
        $byName = [];

        foreach ($nodes as $node) {
            $byName[$node->name] ??= $node;
        }

        $outgoing = [];
        $covers = [];
        $edgeCounts = [Confidence::Confirmed->value => 0, Confidence::Possible->value => 0, Confidence::Unresolved->value => 0];

        foreach ($edges as $edge) {
            if ($edge->kind === EdgeKind::Covers) {
                $covers[$edge->to][$edge->from] = true;
            } elseif (! $edge->kind->isHierarchy()) {
                $outgoing[$edge->from][] = $edge;
                $edgeCounts[$edge->confidence->value]++;
            }
        }

        $entryNodes = array_values(array_filter($nodes, fn (Node $n) => $n->kind->isEntryPoint()));
        usort($entryNodes, fn (Node $a, Node $b) => [$a->kind->value, $a->name] <=> [$b->kind->value, $b->name]);

        $entries = [];
        $used = [];

        foreach ($entryNodes as $entry) {
            $flow = self::flow($entry->name, $outgoing, $depth);
            $entries[] = ['name' => $entry->name, 'kind' => $entry->kind->value, 'flow' => $flow];

            foreach ($flow['nodes'] as $node) {
                $used[$node['n']] = true;
            }
        }

        if ($impact !== null) {
            foreach ($impact->changes as $change) {
                $used[$change->symbol] = true;
            }
        }

        $info = [];
        $coveredBy = [];

        foreach (array_keys($used) as $name) {
            $node = $byName[$name] ?? null;
            $file = $node === null || $node->file === '' ? '' : self::relative($root, $node->file);
            $info[$name] = [
                'kind' => $node === null ? self::guessKind($name) : $node->kind->value,
                'file' => $file,
                'line' => $node === null ? 0 : $node->startLine,
                'end' => $node === null ? 0 : $node->endLine,
            ];

            if (isset($covers[$name])) {
                $tests = array_keys($covers[$name]);
                sort($tests);
                $coveredBy[$name] = $tests;
            }
        }

        return [
            'meta' => [
                'project' => $project !== null && $project !== '' ? $project : basename($root),
                'generatedAt' => date('c'),
                'depth' => $depth,
                'live' => $live,
                'entryPoints' => count($entries),
                'symbols' => count(array_filter($nodes, fn (Node $n) => in_array($n->kind, [NodeKind::Method, NodeKind::Function, NodeKind::Closure], true))),
                'tests' => count(array_filter($nodes, fn (Node $n) => $n->kind === NodeKind::Test)),
                'edges' => $edgeCounts,
            ],
            'nodes' => $info,
            'coveredBy' => $coveredBy,
            'impact' => $impact === null ? null : self::impact($root, $impact, $base),
            'entries' => $entries,
        ];
    }

    /**
     * @param  array<string, list<Edge>>  $outgoing
     * @return array{nodes: list<array{n: string, d: int, c: string}>, links: list<array{int, int, string, string}>, truncated: bool}
     */
    private static function flow(string $entry, array $outgoing, int $maxDepth): array
    {
        $depthOf = [$entry => 0];
        $confidence = [$entry => Confidence::Confirmed->value];
        $order = [$entry];
        $queue = [$entry];

        while ($queue !== []) {
            $current = array_shift($queue);

            if ($depthOf[$current] >= $maxDepth) {
                continue;
            }

            foreach ($outgoing[$current] ?? [] as $edge) {
                if (isset($depthOf[$edge->to])) {
                    continue;
                }

                $depthOf[$edge->to] = $depthOf[$current] + 1;
                $confidence[$edge->to] = self::RANK[$confidence[$current]] <= self::RANK[$edge->confidence->value] ? $confidence[$current] : $edge->confidence->value;
                $order[] = $edge->to;
                $queue[] = $edge->to;
            }
        }

        $truncated = count($order) > self::MAX_NODES_PER_ENTRY;
        $order = array_slice($order, 0, self::MAX_NODES_PER_ENTRY);
        $index = array_flip($order);

        $best = [];

        foreach ($order as $name) {
            foreach ($outgoing[$name] ?? [] as $edge) {
                // Forward links only: they keep the layers readable; cycles and back-edges are left out.
                if (! isset($index[$edge->to]) || $depthOf[$edge->to] <= $depthOf[$name]) {
                    continue;
                }

                $key = $index[$name].'>'.$index[$edge->to];

                if (! isset($best[$key]) || self::RANK[$edge->confidence->value] > self::RANK[$best[$key][2]]) {
                    $best[$key] = [$index[$name], $index[$edge->to], $edge->confidence->value, $edge->kind->value];
                }
            }
        }

        return [
            'nodes' => array_map(fn (string $name) => ['n' => $name, 'd' => $depthOf[$name], 'c' => $confidence[$name]], $order),
            'links' => array_values($best),
            'truncated' => $truncated,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function impact(string $root, ImpactReport $impact, ?string $base): array
    {
        $entries = [];

        foreach ($impact->entries as $entry) {
            $entries[$entry['entry']] = ['confidence' => $entry['confidence']->value, 'depth' => $entry['depth'], 'tested' => $entry['tested']];
        }

        return [
            'base' => $base,
            'changes' => array_map(fn ($c) => ['symbol' => $c->symbol, 'kind' => $c->kind, 'file' => $c->file, 'signature' => $c->signature], $impact->changes),
            'entries' => $entries,
            'tests' => array_map(fn (string $file) => $file === '' ? '' : self::relative($root, $file), $impact->tests),
            'files' => $impact->files,
        ];
    }

    private static function relative(string $root, string $file): string
    {
        $root = rtrim($root, '/').'/';

        return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }

    private static function guessKind(string $name): string
    {
        return match (true) {
            str_starts_with($name, '*::'), str_starts_with($name, '<') => 'dynamic',
            str_contains($name, '::') => 'method',
            default => 'class',
        };
    }
}
