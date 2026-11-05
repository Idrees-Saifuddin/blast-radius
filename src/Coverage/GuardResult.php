<?php

declare(strict_types=1);

namespace BlastRadius\Coverage;

final class GuardResult
{
    public function __construct(
        public readonly bool $safe,
        public readonly string $reason,
    ) {}
}
