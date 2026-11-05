<?php

declare(strict_types=1);

namespace BlastRadius\Runtime;

use Throwable;

/**
 * Records one request's function calls as a tree of timed spans. Instrumented methods call
 * enter() on the way in, leave() on the way out and fail() when an exception passes through.
 *
 * It is static on purpose: instrumented code has to reach it from anywhere with no wiring,
 * and one PHP-FPM process serves one request at a time. It never throws, and does nothing
 * until begin() was called, so instrumented code is harmless outside a traced request.
 */
final class Tracer
{
    private const MAX_SPANS = 20000;

    private static bool $active = false;

    private static int $origin = 0;

    private static int $dropped = 0;

    /** @var list<string> span id => `Class::method` */
    private static array $names = [];

    /** @var list<int> span id => parent span id, -1 for a root */
    private static array $parents = [];

    /** @var list<int> span id => start, in nanoseconds since the request began */
    private static array $starts = [];

    /** @var array<int, int> span id => end; missing while the span is open */
    private static array $ends = [];

    /** @var array<int, array{class: string, message: string, file: string, line: int, x: int}> */
    private static array $failures = [];

    /** @var list<int> */
    private static array $stack = [];

    public static function begin(): void
    {
        self::$active = true;
        self::$origin = hrtime(true);
        self::$dropped = 0;
        self::reset();
    }

    public static function active(): bool
    {
        return self::$active;
    }

    /** hrtime() value, in nanoseconds, at which the request began. */
    public static function origin(): int
    {
        return self::$origin;
    }

    public static function enter(string $name): int
    {
        if (! self::$active) {
            return -1;
        }

        if (count(self::$names) >= self::MAX_SPANS) {
            self::$dropped++;

            return -1;
        }

        $id = count(self::$names);
        self::$names[] = $name;
        self::$parents[] = self::current();
        self::$starts[] = self::now();
        self::$stack[] = $id;

        return $id;
    }

    public static function leave(int $id): void
    {
        if ($id < 0 || ! self::$active || ! isset(self::$names[$id])) {
            return;
        }

        $now = self::now();
        self::$ends[$id] ??= $now;

        // Pop this span, and any child that was left open (a generator that was never finished).
        while (self::$stack !== []) {
            $top = array_pop(self::$stack);

            if ($top === $id) {
                break;
            }

            self::$ends[$top] ??= $now;
        }
    }

    public static function fail(int $id, Throwable $e): void
    {
        if ($id < 0 || ! self::$active || ! isset(self::$names[$id]) || isset(self::$failures[$id])) {
            return;
        }

        self::$failures[$id] = [
            'class' => $e::class,
            'message' => substr($e->getMessage(), 0, 300),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'x' => spl_object_id($e),
        ];
    }

    /** The innermost open span, or -1. */
    public static function current(): int
    {
        return self::$stack === [] ? -1 : self::$stack[array_key_last(self::$stack)];
    }

    /**
     * Stops tracing and hands over what was recorded. Spans still open (a call that never
     * returned, such as a fatal error or exit()) end now.
     *
     * @return array{spans: list<array{n: string, p: int, s: int, e: int, f: array{class: string, message: string, file: string, line: int, x: int}|null}>, dropped: int, endedAt: int}
     */
    public static function end(): array
    {
        $now = self::now();
        $spans = [];

        foreach (self::$names as $id => $name) {
            $spans[] = [
                'n' => $name,
                'p' => self::$parents[$id],
                's' => self::$starts[$id],
                'e' => self::$ends[$id] ?? $now,
                'f' => self::$failures[$id] ?? null,
            ];
        }

        $result = ['spans' => $spans, 'dropped' => self::$dropped, 'endedAt' => $now];

        self::$active = false;
        self::reset();

        return $result;
    }

    private static function now(): int
    {
        return (int) (hrtime(true) - self::$origin);
    }

    private static function reset(): void
    {
        self::$names = [];
        self::$parents = [];
        self::$starts = [];
        self::$ends = [];
        self::$failures = [];
        self::$stack = [];
    }
}
