<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Unit\Runtime;

use BlastRadius\Runtime\Instrumenter;
use BlastRadius\Runtime\Tracer;
use InstrA\Calc;
use InstrB\Service;
use InstrC\Level;
use InstrC\Square;
use InstrD\Paths;
use InstrE\Tricky;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InstrumenterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/blast-instr-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    /**
     * Instruments `$source` as if it were `$name`.php, loads the rewritten copy and returns its path.
     */
    private function load(string $source, string $name = 'Sample'): string
    {
        $original = $this->dir.'/'.$name.'.php';
        file_put_contents($original, $source);

        $transformed = Instrumenter::transform($source, $original);
        $this->assertNotNull($transformed, 'the source should have something to instrument');
        $this->assertSame(substr_count($source, "\n"), substr_count($transformed, "\n"), 'line numbers must not move');

        $copy = $this->dir.'/'.$name.'.instrumented.php';
        file_put_contents($copy, $transformed);
        require $copy;

        return $original;
    }

    /**
     * @return list<array{string, int, int|null}> span name, parent, failed-with-exception flag
     */
    private function shape(): array
    {
        return array_map(fn (array $s) => [$s['n'], $s['p'], $s['f'] === null ? null : 1], Tracer::end()['spans']);
    }

    public function test_methods_report_to_the_tracer_and_behave_exactly_as_before(): void
    {
        $this->load(<<<'PHP'
<?php

declare(strict_types=1);

namespace InstrA;

class Calc
{
    public function __construct(private int $base = 10)
    {
    }

    public function add(int $a, int $b): int
    {
        return $a + $b + $this->twice(0);
    }

    private function twice(int $n): int
    {
        return $n * 2;
    }

    public static function make(): static
    {
        return new static(5);
    }

    public function noop(): void
    {
    }

    public function &reference(array &$items): array
    {
        $items[] = 1;

        return $items;
    }

    public function __invoke(int $x): int
    {
        return $x + $this->base;
    }
}
PHP);

        Tracer::begin();
        $calc = Calc::make();
        $this->assertSame(3, $calc->add(1, 2));
        $this->assertNull($calc->noop());
        $list = [];
        $copy = $calc->reference($list);
        $this->assertSame([1], $list);
        $this->assertSame([1], $copy);
        $this->assertSame(15, $calc(10));

        $this->assertSame([
            ['InstrA\Calc::make', -1, null],
            ['InstrA\Calc::add', -1, null],
            ['InstrA\Calc::twice', 1, null],
            ['InstrA\Calc::noop', -1, null],
            ['InstrA\Calc::reference', -1, null],
            ['InstrA\Calc::__invoke', -1, null],
        ], $this->shape(), 'the constructor is skipped; __invoke and every other method is timed');
    }

    public function test_exceptions_pass_through_unchanged_and_mark_the_spans_they_crossed(): void
    {
        $this->load(<<<'PHP'
<?php

namespace InstrB;

use RuntimeException;

class Service
{
    public function run(): void
    {
        $this->fail();
    }

    private function fail(): void
    {
        throw new RuntimeException('boom');
    }

    public function safe(): string
    {
        try {
            $this->fail();
        } catch (RuntimeException) {
            return 'recovered';
        }

        return 'unreachable';
    }
}
PHP);

        Tracer::begin();
        $service = new Service;

        try {
            $service->run();
            $this->fail('the exception must still be thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
            $this->assertSame(16, $e->getLine(), 'the exception points at the original line');
        }

        $this->assertSame('recovered', $service->safe());

        $spans = Tracer::end()['spans'];
        $byName = [];

        foreach ($spans as $i => $s) {
            $byName[$s['n']][] = ['i' => $i, 'failed' => $s['f'] !== null, 'p' => $s['p']];
        }

        $this->assertTrue($byName['InstrB\Service::run'][0]['failed']);
        $this->assertTrue($byName['InstrB\Service::fail'][0]['failed'], 'the function the exception came from is marked');
        $failed = $spans[$byName['InstrB\Service::fail'][0]['i']]['f'] ?? throw new \LogicException('no failure recorded');
        $this->assertSame('boom', $failed['message']);
        $this->assertTrue($byName['InstrB\Service::fail'][1]['failed'], 'it failed again inside safe()');
        $this->assertFalse($byName['InstrB\Service::safe'][0]['failed'], 'safe() caught it, so it did not fail');
    }

    public function test_generators_static_methods_traits_enums_and_abstract_methods(): void
    {
        $this->load(<<<'PHP'
<?php

namespace InstrC;

interface Shape
{
    public function area(): int;
}

abstract class Base implements Shape
{
    abstract public function name(): string;

    public function describe(): string
    {
        return $this->name().':'.$this->area();
    }
}

trait Greets
{
    public function greet(): string
    {
        return 'hi';
    }
}

enum Level: int
{
    case Low = 1;

    public function label(): string
    {
        return 'low';
    }
}

final class Square extends Base
{
    use Greets;

    public function name(): string
    {
        return 'square';
    }

    public function area(): int
    {
        return 4;
    }

    public function numbers(): \Generator
    {
        yield 1;
        yield 2;
    }
}
PHP);

        Tracer::begin();
        $square = new Square;
        $this->assertSame('square:4', $square->describe());
        $this->assertSame('hi', $square->greet());
        $this->assertSame('low', Level::Low->label());
        $this->assertSame([1, 2], iterator_to_array($square->numbers()));

        $names = array_column(Tracer::end()['spans'], 'n');

        $this->assertContains('InstrC\Base::describe', $names);
        $this->assertContains('InstrC\Square::name', $names);
        $this->assertContains('InstrC\Greets::greet', $names);
        $this->assertContains('InstrC\Level::label', $names);
        $this->assertContains('InstrC\Square::numbers', $names);
        $this->assertNotContains('InstrC\Base::name', $names, 'an abstract method has no body to time');
        $this->assertNotContains('InstrC\Shape::area', $names);
    }

    public function test_file_and_dir_constants_point_at_the_original_file_and_anonymous_classes_are_left_alone(): void
    {
        $original = $this->load(<<<'PHP'
<?php

namespace InstrD;

class Paths
{
    public function where(): array
    {
        $anon = new class
        {
            public function inner(): string
            {
                return 'inner';
            }
        };

        return [__FILE__, __DIR__, __LINE__, $anon->inner()];
    }
}
PHP, 'Paths');

        Tracer::begin();
        $result = (new Paths)->where();

        $this->assertSame([$original, dirname($original), 17, 'inner'], $result);
        $this->assertSame(['InstrD\Paths::where'], array_column(Tracer::end()['spans'], 'n'));
    }

    public function test_attributes_and_defaults_with_brackets_do_not_confuse_the_body_search(): void
    {
        $this->load(<<<'PHP'
<?php

namespace InstrE;

#[\Attribute]
class Tag
{
    public function __construct(public array $options = [])
    {
    }
}

class Tricky
{
    #[Tag(['a' => [1, 2]])]
    public function build(array $items = ['x' => [1]], string $label = 'a{b}'): string
    {
        return $label.count($items);
    }
}
PHP);

        Tracer::begin();
        $this->assertSame('a{b}1', (new Tricky)->build());
        $this->assertSame(['InstrE\Tricky::build'], array_column(Tracer::end()['spans'], 'n'));
    }

    public function test_it_returns_null_when_there_is_nothing_to_time_or_the_code_does_not_parse(): void
    {
        $this->assertNull(Instrumenter::transform('<?php function helper() {}', '/x.php'), 'plain functions are not timed');
        $this->assertNull(Instrumenter::transform('<?php class Empty {}', '/x.php'));
        $this->assertNull(Instrumenter::transform('<?php class {', '/x.php'));
        $this->assertNull(Instrumenter::transform('<?php namespace A; interface I { public function m(); }', '/x.php'));
        $this->assertNull(Instrumenter::transform('<?php namespace A; class OnlyMagic { public function __construct() {} public function __get($n) {} }', '/x.php'));
    }
}
