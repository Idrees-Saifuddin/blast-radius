<?php

declare(strict_types=1);

namespace BlastRadius\Impact;

final class FileChange
{
    /**
     * @param  list<array{int, int}>  $addedRanges  changed line ranges in the new file
     * @param  list<array{int, int}>  $removedRanges  changed line ranges in the old file
     */
    public function __construct(
        public readonly string $path,
        public readonly ?string $oldPath,
        public readonly bool $isNew,
        public readonly bool $isDeleted,
        public readonly array $addedRanges,
        public readonly array $removedRanges,
    ) {}
}
