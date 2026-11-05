<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Unit\Runtime;

use BlastRadius\Runtime\Tracer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TracerTest extends TestCase
{
    /**
     * @param  array{f: array{class: string, message: string, file: string, line: int, x: int}|null}  $span
     * @return array{class: string, message: string, file: string, line: int, x: int}
     */
    private function failure(array $span): array
    {
        return $span['f'] ?? throw new \LogicException('the span has no failure');
    }

    public function test_it_does_nothing_until_a_request_begins(): void
    {
        $this->assertFalse(Tracer::active());
        $this->assertSame(-1, Tracer::enter('A::a'));
        Tracer::leave(0);
        Tracer::fail(0, new RuntimeException('x'));

        Tracer::begin();
        $this->assertSame([], Tracer::end()['spans']);
    }

    public function test_it_records_a_tree_of_timed_spans(): void
    {
        Tracer::begin();
        $outer = Tracer::enter('A::outer');
        $inner = Tracer::enter('A::inner');
        usleep(2000);
        Tracer::leave($inner);
        $sibling = Tracer::enter('A::sibling');
        Tracer::leave($sibling);
        Tracer::leave($outer);
        $result = Tracer::end();

        $spans = $result['spans'];
        $this->assertSame(['A::outer', 'A::inner', 'A::sibling'], array_column($spans, 'n'));
        $this->assertSame([-1, 0, 0], array_column($spans, 'p'));
        $this->assertGreaterThanOrEqual(2_000_000, $spans[1]['e'] - $spans[1]['s'], 'inner took at least 2 ms');
        $this->assertGreaterThanOrEqual($spans[1]['e'] - $spans[1]['s'], $spans[0]['e'] - $spans[0]['s'], 'the parent includes its children');
        $this->assertGreaterThanOrEqual($spans[1]['e'], $spans[2]['s'], 'siblings run one after the other');
        $this->assertFalse(Tracer::active(), 'end() stops tracing');
    }

    public function test_failures_are_recorded_once_per_span(): void
    {
        Tracer::begin();
        $outer = Tracer::enter('A::outer');
        $inner = Tracer::enter('A::inner');
        $error = new RuntimeException('boom');
        Tracer::fail($inner, $error);
        Tracer::leave($inner);
        Tracer::fail($outer, $error);
        Tracer::fail($outer, new RuntimeException('second one is ignored'));
        Tracer::leave($outer);

        $spans = Tracer::end()['spans'];

        $this->assertSame('boom', $this->failure($spans[0])['message']);
        $this->assertSame(RuntimeException::class, $this->failure($spans[1])['class']);
        $this->assertSame($this->failure($spans[0])['x'], $this->failure($spans[1])['x'], 'the same exception is recognisable on every span it passed');
        $this->assertSame(__FILE__, $this->failure($spans[1])['file']);
    }

    public function test_spans_left_open_are_closed_at_the_end_and_late_leaves_are_harmless(): void
    {
        Tracer::begin();
        $outer = Tracer::enter('A::outer');
        Tracer::enter('A::neverLeft');
        Tracer::leave($outer);
        $result = Tracer::end();

        $this->assertSame('A::neverLeft', $result['spans'][1]['n']);
        $this->assertNotNull($result['spans'][1]['e']);
        $this->assertSame(-1, Tracer::current());

        Tracer::leave(1);
        $this->addToAssertionCount(1);
    }

    public function test_it_stops_recording_beyond_its_limit_and_says_how_many_it_dropped(): void
    {
        Tracer::begin();

        for ($i = 0; $i < 20005; $i++) {
            Tracer::leave(Tracer::enter('A::loop'));
        }

        $result = Tracer::end();

        $this->assertCount(20000, $result['spans']);
        $this->assertSame(5, $result['dropped']);
    }
}
