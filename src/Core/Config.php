<?php

declare(strict_types=1);

namespace BlastRadius\Core;

final class Config
{
    /**
     * @param  list<string>  $paths  files or directories to scan, relative to the project root
     * @param  list<string>  $excludes  relative paths or fnmatch patterns to skip
     * @param  int  $possibleFanOut  an untyped `$x->m()` links to every class declaring `m()` as
     *                               POSSIBLE, up to this many; beyond that it is one UNRESOLVED edge
     */
    public function __construct(
        public readonly array $paths = ['app', 'routes', 'database', 'config'],
        public readonly array $excludes = [],
        public readonly int $possibleFanOut = 10,
    ) {}
}
