<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Unit\Static;

use BlastRadius\Core\Config;
use BlastRadius\Core\EdgeKind;
use BlastRadius\Output\HierarchyRenderer;
use BlastRadius\Static\Hierarchy;
use BlastRadius\Static\SymbolIndexer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HierarchyTest extends TestCase
{
    private Hierarchy $hierarchy;

    protected function setUp(): void
    {
        $table = (new SymbolIndexer(new Config(paths: ['Hierarchy'])))->index(__DIR__.'/../../Fixtures');
        $this->hierarchy = new Hierarchy($table);
    }

    public function test_it_records_declared_edges_with_their_kind(): void
    {
        $kinds = [];

        foreach ($this->hierarchy->outgoing('App\Shapes\Circle') as $edge) {
            $kinds[$edge->to] = $edge->kind;
        }

        $this->assertSame([
            'App\Shapes\Resizable' => EdgeKind::Implements,
            'App\Shapes\Shape' => EdgeKind::Extends,
        ], $kinds);
        $this->assertSame('App\Shapes\Shape', $this->hierarchy->parentOf('App\Shapes\Circle'));
        $this->assertNull($this->hierarchy->parentOf('App\Shapes\Shape'));
    }

    public function test_it_lists_every_ancestor(): void
    {
        $ancestors = $this->hierarchy->ancestors('App\Shapes\Ring');

        foreach (['Circle', 'Shape', 'Drawable', 'Resizable', 'Loud', 'HasName'] as $name) {
            $this->assertContains('App\Shapes\\'.$name, $ancestors);
        }

        $this->assertSame('App\Shapes\Circle', $ancestors[0], 'nearest first');
    }

    /**
     * @return array<string, array{string, string, string|null}>
     */
    public static function methodResolution(): array
    {
        return [
            'own method wins' => ['Ring', 'area', 'App\Shapes\Ring::area'],
            'inherited from parent' => ['Ring', 'draw', 'App\Shapes\Circle::draw'],
            'trait through trait' => ['Ring', 'name', 'App\Shapes\HasName::name'],
            'trait beats inherited method' => ['Ring', 'describe', 'App\Shapes\HasName::describe'],
            'parent own beats its trait' => ['Circle', 'describe', 'App\Shapes\Shape::describe'],
            'interface extends interface' => ['Resizable', 'draw', 'App\Shapes\Drawable::draw'],
            'method names are case-insensitive' => ['Circle', 'DRAW', 'App\Shapes\Circle::draw'],
            'undeclared method' => ['Circle', 'nope', null],
        ];
    }

    /**
     * @dataProvider methodResolution
     */
    #[DataProvider('methodResolution')]
    public function test_it_resolves_methods_like_php(string $class, string $method, ?string $expected): void
    {
        $this->assertSame($expected, $this->hierarchy->resolveMethod('App\Shapes\\'.$class, $method));
    }

    public function test_it_survives_cycles(): void
    {
        $dir = sys_get_temp_dir().'/blast-'.uniqid();
        mkdir($dir.'/app', 0777, true);
        file_put_contents($dir.'/app/Loop.php', '<?php namespace L; class A extends B {} class B extends A {}');

        try {
            $hierarchy = new Hierarchy((new SymbolIndexer(new Config(paths: ['app'])))->index($dir));
        } finally {
            unlink($dir.'/app/Loop.php');
            rmdir($dir.'/app');
            rmdir($dir);
        }

        $this->assertNull($hierarchy->resolveMethod('L\A', 'missing'));
        $this->assertSame(['L\B'], $hierarchy->ancestors('L\A'));
    }

    public function test_it_renders_trees_in_both_directions(): void
    {
        $renderer = new HierarchyRenderer($this->hierarchy);

        $this->assertSame([
            'App\Shapes\Drawable',
            '├── App\Shapes\Resizable  [extends]',
            '│   └── App\Shapes\Circle  [implements]',
            '│       └── App\Shapes\Ring  [extends]',
            '└── App\Shapes\Shape  [implements]',
            '    └── App\Shapes\Circle  [extends]',
            '        └── App\Shapes\Ring  [extends]',
        ], $renderer->descendants('App\Shapes\Drawable'));

        $this->assertSame([
            'App\Shapes\Loud',
            '└── App\Shapes\HasName  [uses-trait]',
        ], $renderer->ancestors('App\Shapes\Loud'));
    }

    public function test_roots_are_the_tops_of_the_trees(): void
    {
        $this->assertSame(['App\Shapes\Drawable', 'App\Shapes\HasName'], $this->hierarchy->roots());
    }
}
