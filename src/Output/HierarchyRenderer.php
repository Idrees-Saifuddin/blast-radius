<?php

declare(strict_types=1);

namespace BlastRadius\Output;

use BlastRadius\Core\Edge;
use BlastRadius\Static\Hierarchy;

final class HierarchyRenderer
{
    public function __construct(private readonly Hierarchy $hierarchy) {}

    /**
     * Tree of the types that extend, implement or use `$type`.
     *
     * @return list<string>
     */
    public function descendants(string $type): array
    {
        return $this->tree($type, fn (string $t) => $this->hierarchy->incoming($t), fn (Edge $e) => $e->from);
    }

    /**
     * Tree of the types `$type` extends, implements or uses.
     *
     * @return list<string>
     */
    public function ancestors(string $type): array
    {
        return $this->tree($type, fn (string $t) => $this->hierarchy->outgoing($t), fn (Edge $e) => $e->to);
    }

    /**
     * @param  callable(string): list<Edge>  $edges
     * @param  callable(Edge): string  $next
     * @param  list<string>  $path
     * @return list<string>
     */
    private function tree(string $type, callable $edges, callable $next, string $prefix = '', array $path = [], ?Edge $via = null): array
    {
        $lines = [$via === null ? $type : "{$type}  [{$via->kind->value}]"];
        $path[] = $type;
        $children = array_values(array_filter($edges($type), fn (Edge $e) => ! in_array($next($e), $path, true)));

        foreach ($children as $i => $edge) {
            $last = $i === array_key_last($children);
            $sub = $this->tree($next($edge), $edges, $next, $prefix.($last ? '    ' : '│   '), $path, $edge);
            $lines[] = $prefix.($last ? '└── ' : '├── ').$sub[0];

            foreach (array_slice($sub, 1) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
