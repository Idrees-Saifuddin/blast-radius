<?php

declare(strict_types=1);

namespace BlastRadius\Coverage;

use BlastRadius\Core\Confidence;
use BlastRadius\Core\Edge;
use BlastRadius\Core\EdgeKind;
use BlastRadius\Core\Node;
use BlastRadius\Core\NodeKind;
use BlastRadius\Static\SymbolTable;

/**
 * Turns "line L of file F ran under test T" into "test T executed method M": for every
 * covered line the innermost method, function or closure containing it is marked covered.
 */
final class CoverageMap
{
    /**
     * @param  array<string, array<int, mixed>>  $lineCoverage  file => line => tests that ran it
     * @param  array<int, string>  $testIds  test index => id, for php-code-coverage versions that index tests
     * @param  array<string, string>  $testFiles  test class => file
     * @return array{nodes: list<Node>, edges: list<Edge>}
     */
    public static function build(array $lineCoverage, array $testIds, SymbolTable $table, array $testFiles): array
    {
        $ranges = [];

        foreach ($table->nodes as $node) {
            if (in_array($node->kind, [NodeKind::Method, NodeKind::Function, NodeKind::Closure], true)) {
                $ranges[$node->file][] = $node;
            }
        }

        $covered = [];

        foreach ($lineCoverage as $file => $lines) {
            if (! isset($ranges[$file])) {
                continue;
            }

            foreach ($lines as $line => $tests) {
                foreach (self::testsOf($tests, $testIds) as $test) {
                    $node = self::innermost($ranges[$file], (int) $line);

                    if ($node !== null) {
                        $covered[$test][$node->name] = true;
                    }
                }
            }
        }

        ksort($covered);
        $nodes = [];
        $edges = [];

        foreach ($covered as $test => $methods) {
            $testFile = $testFiles[explode('::', $test)[0]] ?? '';
            $nodes[] = new Node(NodeKind::Test, $test, $testFile, 0, 0);

            ksort($methods);

            foreach (array_keys($methods) as $method) {
                $edges[] = new Edge($test, $method, EdgeKind::Covers, Confidence::Confirmed, $testFile, 0);
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * @param  array<int, string>  $testIds
     * @return list<string> test ids without data-set suffixes (`Class::method#3` becomes `Class::method`)
     */
    private static function testsOf(mixed $tests, array $testIds): array
    {
        if (! is_array($tests)) {
            return [];
        }

        $ids = [];

        foreach ($tests as $key => $value) {
            $id = match (true) {
                is_string($value) => $value,
                is_int($key) && isset($testIds[$key]) => $testIds[$key],
                default => null,
            };

            if ($id !== null && $id !== '') {
                $ids[explode('#', $id)[0]] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @param  list<Node>  $nodes
     */
    private static function innermost(array $nodes, int $line): ?Node
    {
        $best = null;

        foreach ($nodes as $node) {
            if ($line >= $node->startLine && $line <= $node->endLine && ($best === null || $node->endLine - $node->startLine < $best->endLine - $best->startLine)) {
                $best = $node;
            }
        }

        return $best;
    }
}
