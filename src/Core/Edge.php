<?php

declare(strict_types=1);

namespace BlastRadius\Core;

/**
 * A directed relation `from -> to`. For hierarchy edges `from` is the class-like that
 * declares the relation and `to` is its parent, interface or trait.
 */
final class Edge
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly EdgeKind $kind,
        public readonly Confidence $confidence,
        public readonly string $file,
        public readonly int $line,
    ) {}
}
