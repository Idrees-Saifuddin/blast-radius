<?php

declare(strict_types=1);

namespace BlastRadius\Core;

/**
 * A symbol in the code graph. Methods are named `Fqcn::method`.
 */
final class Node
{
    public function __construct(
        public readonly NodeKind $kind,
        public readonly string $name,
        public readonly string $file,
        public readonly int $startLine,
        public readonly int $endLine,
    ) {}
}
