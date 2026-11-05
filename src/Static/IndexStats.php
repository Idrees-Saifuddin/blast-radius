<?php

declare(strict_types=1);

namespace BlastRadius\Static;

final class IndexStats
{
    /**
     * @param  int  $files  PHP files in scope
     * @param  int  $parsed  files read and analysed again because they are new or changed
     * @param  int  $reused  files whose cached symbols were kept
     * @param  int  $removed  files that no longer exist
     * @param  int  $resolved  files whose calls were resolved again
     * @param  bool  $fullResolution  a declaration changed, so every file's calls were resolved again
     */
    public function __construct(
        public readonly int $files,
        public readonly int $parsed,
        public readonly int $reused,
        public readonly int $removed,
        public readonly int $resolved,
        public readonly bool $fullResolution,
    ) {}
}
