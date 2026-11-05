<?php

declare(strict_types=1);

namespace BlastRadius\Impact;

/**
 * A changed symbol. `signature` is set when the declaration line itself was edited (parameters,
 * return type, visibility), which can break callers; a body-only edit leaves it false.
 */
final class Change
{
    public const ADDED = 'added';

    public const MODIFIED = 'modified';

    public const DELETED = 'deleted';

    public function __construct(
        public readonly string $symbol,
        public readonly string $kind,
        public readonly string $file,
        public readonly bool $signature = false,
    ) {}
}
