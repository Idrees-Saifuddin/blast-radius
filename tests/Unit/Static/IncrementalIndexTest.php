<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Unit\Static;

use BlastRadius\Core\Config;
use BlastRadius\Static\SymbolIndexer;
use BlastRadius\Static\SymbolTable;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class IncrementalIndexTest extends TestCase
{
    private string $root;

    private string $db;

    protected function setUp(): void
    {
        $this->root = realpath(sys_get_temp_dir()).'/blast-'.uniqid();
        mkdir($this->root.'/app', 0777, true);
        $this->db = $this->root.'/.blastradius/graph.sqlite';

        $this->write('A.php', '<?php namespace M; class A { function go($x) { $x->run(); } }');
        $this->write('B.php', '<?php namespace M; class B { function run() {} }');
        $this->write('C.php', '<?php namespace M; class C { function noop() { return 1; } }');
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->root);
    }

    private function write(string $name, string $code): void
    {
        file_put_contents($this->root.'/app/'.$name, $code);
    }

    private function cached(): SymbolTable
    {
        return (new SymbolIndexer(new Config(paths: ['app']), $this->db))->index($this->root);
    }

    /**
     * @return array{list<string>, list<string>, list<string>}
     */
    private function describe(SymbolTable $table): array
    {
        $nodes = array_map(fn ($n) => "{$n->kind->value} {$n->name} {$n->startLine}-{$n->endLine}", $table->nodes);
        $edges = array_map(fn ($e) => "{$e->from} -> {$e->to} [{$e->kind->value}] {$e->confidence->value} line {$e->line}", $table->edges);
        sort($nodes);
        sort($edges);

        return [$nodes, $edges, array_keys($table->errors)];
    }

    private function assertMatchesFreshBuild(SymbolTable $incremental): void
    {
        $fresh = (new SymbolIndexer(new Config(paths: ['app'])))->index($this->root);

        $this->assertSame($this->describe($fresh), $this->describe($incremental), 'incremental result must equal a full rebuild');
    }

    public function test_first_build_analyses_everything(): void
    {
        $table = $this->cached();

        $this->assertSame([3, 3, 0, 0, 3, true], $this->statsOf($table));
        $this->assertFileExists($this->db);
        $this->assertMatchesFreshBuild($table);
    }

    public function test_an_unchanged_code_base_is_not_analysed_again(): void
    {
        $this->cached();
        $table = $this->cached();

        $this->assertSame([3, 0, 3, 0, 0, false], $this->statsOf($table));
        $this->assertMatchesFreshBuild($table);
    }

    public function test_editing_a_method_body_only_resolves_that_file_again(): void
    {
        $this->cached();
        $this->write('C.php', '<?php namespace M; class C { function noop() { return 22; } }');

        $table = $this->cached();

        $this->assertSame([3, 1, 2, 0, 1, false], $this->statsOf($table));
        $this->assertMatchesFreshBuild($table);
    }

    public function test_a_new_declaration_makes_every_file_resolve_its_calls_again(): void
    {
        $this->cached();
        $this->write('D.php', '<?php namespace M; class D { function run() {} }');

        $table = $this->cached();

        $this->assertSame([4, 1, 3, 0, 4, true], $this->statsOf($table));
        // A::go calls an untyped $x->run(): it must now see D::run as well
        $targets = array_map(fn ($e) => $e->to, array_filter($table->edges, fn ($e) => $e->from === 'M\A::go'));
        sort($targets);
        $this->assertSame(['M\B::run', 'M\D::run'], $targets);
        $this->assertMatchesFreshBuild($table);
    }

    public function test_a_deleted_file_is_removed_and_dependents_are_updated(): void
    {
        $this->cached();
        unlink($this->root.'/app/B.php');

        $table = $this->cached();

        $this->assertSame([2, 0, 2, 1, 2, true], $this->statsOf($table));
        $this->assertSame([], array_values(array_filter($table->edges, fn ($e) => $e->from === 'M\A::go')));
        $this->assertMatchesFreshBuild($table);
    }

    public function test_a_file_that_stops_parsing_is_reported_and_recovers(): void
    {
        $this->cached();
        $this->write('C.php', '<?php class {');

        $broken = $this->cached();
        $this->assertCount(1, $broken->errors);
        $this->assertMatchesFreshBuild($broken);

        $this->write('C.php', '<?php namespace M; class C { function noop() { return 1; } }');
        $fixed = $this->cached();
        $this->assertSame([], $fixed->errors);
        $this->assertMatchesFreshBuild($fixed);
    }

    public function test_changing_the_config_starts_from_scratch(): void
    {
        $this->cached();
        $table = (new SymbolIndexer(new Config(paths: ['app'], possibleFanOut: 1), $this->db))->index($this->root);

        $this->assertSame([3, 3, 0, 0, 3, true], $this->statsOf($table));
    }

    /**
     * @return array{int, int, int, int, int, bool}
     */
    private function statsOf(SymbolTable $table): array
    {
        $stats = $table->stats;
        $this->assertNotNull($stats);

        return [$stats->files, $stats->parsed, $stats->reused, $stats->removed, $stats->resolved, $stats->fullResolution];
    }
}
