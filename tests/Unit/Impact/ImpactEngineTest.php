<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Unit\Impact;

use BlastRadius\Core\Confidence;
use BlastRadius\Core\Edge;
use BlastRadius\Core\EdgeKind;
use BlastRadius\Core\Node;
use BlastRadius\Core\NodeKind;
use BlastRadius\Impact\Change;
use BlastRadius\Impact\ChangeMapper;
use BlastRadius\Impact\FileChange;
use BlastRadius\Impact\ImpactAnalyzer;
use BlastRadius\Output\ImpactRenderer;
use BlastRadius\Output\ReportData;
use BlastRadius\Static\SymbolTable;
use PHPUnit\Framework\TestCase;

final class ImpactEngineTest extends TestCase
{
    private function edge(string $from, string $to, Confidence $c = Confidence::Confirmed, EdgeKind $k = EdgeKind::Calls): Edge
    {
        return new Edge($from, $to, $k, $c, '', 0);
    }

    public function test_it_maps_changed_lines_to_added_modified_and_deleted_symbols(): void
    {
        $new = "<?php\nnamespace A;\nclass S\n{\n    public function keep()\n    {\n        return 2;\n    }\n\n    public function fresh()\n    {\n    }\n}\n";
        $old = "<?php\nnamespace A;\nclass S\n{\n    public function keep()\n    {\n        return 1;\n    }\n\n    public function gone()\n    {\n    }\n}\n";

        $table = new SymbolTable([
            new Node(NodeKind::Class_, 'A\S', '/r/app/S.php', 3, 13),
            new Node(NodeKind::Method, 'A\S::keep', '/r/app/S.php', 5, 8),
            new Node(NodeKind::Method, 'A\S::fresh', '/r/app/S.php', 10, 12),
        ]);

        $changes = ChangeMapper::map(
            [new FileChange('app/S.php', 'app/S.php', false, false, [[7, 7], [10, 12]], [[7, 7], [10, 12]])],
            $table,
            '/r',
            fn () => $old,
        );

        $this->assertSame(
            ['A\S::fresh' => 'added', 'A\S::gone' => 'deleted', 'A\S::keep' => 'modified'],
            array_column(array_map(fn ($c) => [$c->symbol, $c->kind], $changes), 1, 0),
        );
    }

    public function test_the_confidence_rank_tables_cover_every_confidence_level(): void
    {
        $values = array_map(fn (Confidence $c) => $c->value, Confidence::cases());
        sort($values);

        foreach ([ImpactAnalyzer::class, ReportData::class] as $class) {
            $rank = (new \ReflectionClass($class))->getConstant('RANK');
            $this->assertIsArray($rank);
            $keys = array_keys($rank);
            sort($keys);

            $this->assertSame($values, $keys, $class.'::RANK must list every Confidence value');
        }
    }

    public function test_it_flags_signature_changes_and_treats_moved_symbols_as_modified(): void
    {
        $old = "<?php\nnamespace A;\nclass S\n{\n    public function run()\n    {\n        return 1;\n    }\n}\n";
        $table = new SymbolTable([
            new Node(NodeKind::Class_, 'A\S', '/r/app/S.php', 3, 9),
            new Node(NodeKind::Method, 'A\S::run', '/r/app/S.php', 5, 8),
            new Node(NodeKind::Method, 'A\Moved::go', '/r/app/New/Moved.php', 5, 8),
        ]);

        $changes = ChangeMapper::map([
            // declaration line 5 edited: a signature change; line 7 only a body edit elsewhere
            new FileChange('app/S.php', 'app/S.php', false, false, [[5, 5]], [[5, 5]]),
            // Moved::go: removed from one file and added in another
            new FileChange('app/Old/Moved.php', 'app/Old/Moved.php', false, true, [], [[5, 8]]),
            new FileChange('app/New/Moved.php', null, true, false, [[5, 8]], []),
        ], $table, '/r', fn (string $path) => $path === 'app/S.php' ? $old : "<?php\nnamespace A;\nclass Moved\n{\n    public function go()\n    {\n        return 1;\n    }\n}\n");

        $summary = [];

        foreach ($changes as $c) {
            $summary[$c->symbol] = [$c->kind, $c->signature];
        }

        $this->assertSame(['A\Moved::go' => ['modified', false], 'A\S::run' => ['modified', true]], $summary);
    }

    public function test_it_finds_entry_points_with_the_weakest_confidence_and_flags_untested_ones(): void
    {
        $nodes = [
            new Node(NodeKind::Route, 'route GET /a', '', 0, 0),
            new Node(NodeKind::Route, 'route GET /b', '', 0, 0),
            new Node(NodeKind::Job, 'job J', '', 0, 0),
            new Node(NodeKind::Test, 'T::t', '/tests/T.php', 0, 0),
        ];
        $edges = [
            $this->edge('route GET /a', 'C::a'),
            $this->edge('C::a', 'S::change'),
            $this->edge('route GET /b', 'C::b'),
            $this->edge('C::b', 'S::change', Confidence::Possible),
            $this->edge('job J', 'S::change'),
            $this->edge('T::t', 'C::a', Confidence::Confirmed, EdgeKind::Covers),
        ];

        $report = ImpactAnalyzer::analyze([new Change('S::change', Change::MODIFIED, 'f')], $nodes, $edges);

        $byEntry = [];

        foreach ($report->entries as $e) {
            $byEntry[$e['entry']] = [$e['confidence']->value, $e['depth'], $e['tested']];
        }

        $this->assertSame([
            'job J' => ['CONFIRMED', 1, false],
            'route GET /a' => ['CONFIRMED', 2, true],
            'route GET /b' => ['POSSIBLE', 2, false],
        ], $byEntry);
        // CONFIRMED before POSSIBLE, then shallower first
        $this->assertSame(['job J', 'route GET /a', 'route GET /b'], array_column($report->entries, 'entry'));
        $this->assertSame(['job J'], $report->confirmedUntested());
        // T::t covers C::a (a caller), not the changed method itself, so it is not a test to run
        $this->assertSame([], $report->tests);
        $this->assertSame(['route GET /a', 'C::a', 'S::change'], $report->entries[1]['path']);
    }

    public function test_added_symbols_have_no_callers_to_report_and_output_formats_render(): void
    {
        $report = ImpactAnalyzer::analyze([new Change('S::fresh', Change::ADDED, 'f')], [], []);

        $this->assertSame([], $report->entries);
        $this->assertStringContainsString('Changed: S::fresh   (added)', ImpactRenderer::render($report, 'text'));
        $this->assertStringContainsString('"kind": "added"', ImpactRenderer::render($report, 'json'));
        $this->assertStringContainsString('**added** `S::fresh`', ImpactRenderer::render($report, 'markdown'));
        $this->assertStringContainsString('No PHP symbols changed', ImpactRenderer::render(ImpactAnalyzer::analyze([], [], []), 'text'));
    }
}
