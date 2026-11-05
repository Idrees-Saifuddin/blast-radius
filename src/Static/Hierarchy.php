<?php

declare(strict_types=1);

namespace BlastRadius\Static;

use BlastRadius\Core\Edge;
use BlastRadius\Core\EdgeKind;
use BlastRadius\Core\NodeKind;

/**
 * Class hierarchy built from the declared extends / implements / uses-trait edges,
 * with PHP's method precedence: own method, then used traits, then the parent, then
 * interfaces. Types outside the scanned code (vendor, framework) appear as edge targets
 * but have no methods, so resolution stops there.
 */
final class Hierarchy
{
    /** Order in which PHP looks for an inherited method. */
    private const RESOLUTION_ORDER = [EdgeKind::UsesTrait, EdgeKind::Extends, EdgeKind::Implements];

    /** @var array<string, list<Edge>> */
    private array $outgoing = [];

    /** @var array<string, list<Edge>> */
    private array $incoming = [];

    /** @var array<string, array<string, string>> type => lower-cased method => `Type::method` */
    private array $methods = [];

    /** @var array<string, true> */
    private array $types = [];

    /** @var array<string, array<string, string>> type => property => declared class type */
    private array $properties;

    public function __construct(SymbolTable $table)
    {
        $this->properties = $table->propertyTypes;

        foreach ($table->edges as $edge) {
            if (! $edge->kind->isHierarchy()) {
                continue;
            }

            $this->outgoing[$edge->from][] = $edge;
            $this->incoming[$edge->to][] = $edge;
        }

        foreach ($table->nodes as $node) {
            if ($node->kind === NodeKind::Method) {
                $split = (int) strrpos($node->name, '::');
                $this->methods[substr($node->name, 0, $split)][strtolower(substr($node->name, $split + 2))] = $node->name;
            } elseif ($node->kind->isClassLike()) {
                $this->types[$node->name] = true;
            }
        }
    }

    /**
     * Classes, interfaces, traits and enums declared in the scanned code.
     *
     * @return list<string>
     */
    public function typeNames(): array
    {
        $names = array_keys($this->types);
        sort($names);

        return $names;
    }

    public function declares(string $type): bool
    {
        return isset($this->types[$type]);
    }

    public function isFacade(string $type): bool
    {
        return $this->isA($type, 'Illuminate\\Support\\Facades\\Facade');
    }

    /** Whether `$type` is `$ancestor` or extends, implements or uses it, at any depth. */
    public function isA(string $type, string $ancestor): bool
    {
        return $type === $ancestor || in_array($ancestor, $this->ancestors($type), true);
    }

    public function knows(string $type): bool
    {
        return isset($this->types[$type]) || isset($this->outgoing[$type]) || isset($this->incoming[$type]);
    }

    /**
     * Edges to the types this one extends, implements or uses.
     *
     * @return list<Edge>
     */
    public function outgoing(string $type): array
    {
        return $this->sorted($this->outgoing[$type] ?? [], fn (Edge $e) => $e->to);
    }

    /**
     * Edges from the types that extend, implement or use this one.
     *
     * @return list<Edge>
     */
    public function incoming(string $type): array
    {
        return $this->sorted($this->incoming[$type] ?? [], fn (Edge $e) => $e->from);
    }

    public function parentOf(string $class): ?string
    {
        foreach ($this->outgoing[$class] ?? [] as $edge) {
            if ($edge->kind === EdgeKind::Extends) {
                return $edge->to;
            }
        }

        return null;
    }

    /**
     * Every type reachable through extends, implements and uses-trait, nearest first.
     *
     * @return list<string>
     */
    public function ancestors(string $type): array
    {
        $found = [];
        $queue = [$type];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($this->outgoing($current) as $edge) {
                if ($edge->to !== $type && ! in_array($edge->to, $found, true)) {
                    $found[] = $edge->to;
                    $queue[] = $edge->to;
                }
            }
        }

        return $found;
    }

    /**
     * Every type below this one: subclasses, implementing classes and sub-interfaces, at any depth.
     *
     * @return list<string>
     */
    public function descendants(string $type): array
    {
        $found = [];
        $queue = [$type];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($this->incoming[$current] ?? [] as $edge) {
                if ($edge->kind !== EdgeKind::UsesTrait && $edge->from !== $type && ! in_array($edge->from, $found, true)) {
                    $found[] = $edge->from;
                    $queue[] = $edge->from;
                }
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Other implementations of `$method` that may run when it is called on a `$type` that is
     * really a subclass or an implementation of an interface (late binding).
     *
     * @return list<string>
     */
    public function overriders(string $type, string $method): array
    {
        $base = $this->resolveMethod($type, $method);
        $found = [];

        foreach ($this->descendants($type) as $descendant) {
            $resolved = $this->resolveMethod($descendant, $method);

            if ($resolved !== null && $resolved !== $base && ! in_array($resolved, $found, true)) {
                $found[] = $resolved;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Every declaration of `$method` in the scanned code, as `Type::method`.
     *
     * @return list<string>
     */
    public function declarationsOf(string $method): array
    {
        $method = strtolower($method);
        $found = [];

        foreach ($this->methods as $methods) {
            if (isset($methods[$method])) {
                $found[] = $methods[$method];
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Declared class type of `$property` on `$class`, looked up through traits and parents.
     */
    public function propertyType(string $class, string $property): ?string
    {
        return $this->find($class, fn (string $type) => $this->properties[$type][$property] ?? null, []);
    }

    /**
     * The `Type::method` that runs when `$method` is called on `$class`, or null when it
     * is not declared in the scanned code.
     */
    public function resolveMethod(string $class, string $method): ?string
    {
        $method = strtolower($method);

        return $this->find($class, fn (string $type) => $this->methods[$type][$method] ?? null, []);
    }

    /**
     * Walks the hierarchy in PHP's lookup order and returns the first hit.
     *
     * @param  callable(string): ?string  $lookup
     * @param  array<string, true>  $seen
     */
    private function find(string $type, callable $lookup, array $seen): ?string
    {
        if (isset($seen[$type])) {
            return null;
        }

        $seen[$type] = true;

        if (($hit = $lookup($type)) !== null) {
            return $hit;
        }

        foreach (self::RESOLUTION_ORDER as $kind) {
            foreach ($this->outgoing[$type] ?? [] as $edge) {
                if ($edge->kind === $kind && ($hit = $this->find($edge->to, $lookup, $seen)) !== null) {
                    return $hit;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<Edge>  $edges
     * @param  callable(Edge): string  $key
     * @return list<Edge>
     */
    private function sorted(array $edges, callable $key): array
    {
        usort($edges, fn (Edge $a, Edge $b) => $key($a) <=> $key($b));

        return $edges;
    }

    /**
     * Types that nothing in the scanned code extends, implements or uses a parent of: the tops of the trees.
     *
     * @return list<string>
     */
    public function roots(): array
    {
        $roots = array_filter(
            array_keys($this->types),
            fn (string $type) => ! array_filter($this->outgoing[$type] ?? [], fn (Edge $e) => isset($this->types[$e->to])),
        );
        sort($roots);

        return $roots;
    }
}
