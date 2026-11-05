<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Unit\Coverage;

use BlastRadius\Core\Confidence;
use BlastRadius\Core\Edge;
use BlastRadius\Core\EdgeKind;
use BlastRadius\Core\Node;
use BlastRadius\Core\NodeKind;
use BlastRadius\Coverage\CoverageMap;
use BlastRadius\Static\SymbolTable;
use PHPUnit\Framework\TestCase;

final class CoverageMapTest extends TestCase
{
    private function table(): SymbolTable
    {
        return new SymbolTable([
            new Node(NodeKind::Class_, 'A\Svc', '/app/Svc.php', 3, 30),
            new Node(NodeKind::Method, 'A\Svc::run', '/app/Svc.php', 5, 12),
            new Node(NodeKind::Method, 'A\Svc::other', '/app/Svc.php', 14, 20),
            new Node(NodeKind::Closure, 'closure@/app/Svc.php:7', '/app/Svc.php', 7, 9),
            new Node(NodeKind::Function, 'A\helper', '/app/helpers.php', 3, 6),
        ]);
    }

    /**
     * @param  array{nodes: list<Node>, edges: list<Edge>}  $map
     * @return list<string>
     */
    private function edges(array $map): array
    {
        return array_map(fn ($e) => "{$e->from} -> {$e->to}", $map['edges']);
    }

    public function test_covered_lines_mark_the_innermost_method_or_closure(): void
    {
        $map = CoverageMap::build([
            '/app/Svc.php' => [6 => ['T\FooTest::test_a'], 8 => ['T\FooTest::test_a'], 15 => ['T\BarTest::test_b'], 25 => ['T\BarTest::test_b']],
            '/app/helpers.php' => [4 => ['T\BarTest::test_b']],
            '/app/other.php' => [1 => ['T\BarTest::test_b']],
        ], [], $this->table(), ['T\FooTest' => '/tests/FooTest.php']);

        $this->assertSame([
            'T\BarTest::test_b -> A\Svc::other',
            'T\BarTest::test_b -> A\helper',
            'T\FooTest::test_a -> A\Svc::run',
            'T\FooTest::test_a -> closure@/app/Svc.php:7',
        ], $this->edges($map));

        $this->assertSame(EdgeKind::Covers, $map['edges'][0]->kind);
        $this->assertSame(Confidence::Confirmed, $map['edges'][0]->confidence);
        $this->assertSame(NodeKind::Test, $map['nodes'][0]->kind);
        $this->assertSame(['', '/tests/FooTest.php'], array_column($map['nodes'], 'file'));
    }

    public function test_lines_outside_any_method_and_uncovered_lines_are_ignored(): void
    {
        $map = CoverageMap::build([
            '/app/Svc.php' => [4 => ['T\FooTest::test_a'], 6 => null, 7 => [], 13 => 'not an array'],
        ], [], $this->table(), []);

        $this->assertSame([], $map['edges']);
        $this->assertSame([], $map['nodes']);
    }

    public function test_data_set_suffixes_are_merged_into_one_test(): void
    {
        $map = CoverageMap::build([
            '/app/Svc.php' => [6 => ['T\FooTest::test_a#0', 'T\FooTest::test_a#1']],
        ], [], $this->table(), []);

        $this->assertSame(['T\FooTest::test_a -> A\Svc::run'], $this->edges($map));
    }

    public function test_it_understands_index_based_coverage_from_newer_versions(): void
    {
        // php-code-coverage 14.3+: each line maps test index => hit flag; ids are listed separately
        $map = CoverageMap::build([
            '/app/Svc.php' => [6 => [0 => 1, 1 => 1], 15 => [1 => 1]],
        ], ['T\FooTest::test_a', 'T\BarTest::test_b'], $this->table(), []);

        $this->assertSame([
            'T\BarTest::test_b -> A\Svc::other',
            'T\BarTest::test_b -> A\Svc::run',
            'T\FooTest::test_a -> A\Svc::run',
        ], $this->edges($map));
    }
}
