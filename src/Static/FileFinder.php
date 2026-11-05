<?php

declare(strict_types=1);

namespace BlastRadius\Static;

use BlastRadius\Core\Config;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class FileFinder
{
    /** Never scanned, whatever the config says. */
    private const ALWAYS_SKIPPED = ['vendor', 'node_modules', 'storage'];

    public function __construct(private readonly Config $config) {}

    /**
     * @return list<string> absolute paths of the PHP files to analyse, sorted
     */
    public function find(string $basePath): array
    {
        $base = realpath($basePath);

        if ($base === false) {
            return [];
        }

        $files = [];

        foreach ($this->config->paths as $path) {
            $full = $base.'/'.trim($path, '/');

            if (is_file($full)) {
                if ($this->isPhp($full) && ! $this->isExcluded($this->relative($base, $full))) {
                    $files[$full] = true;
                }

                continue;
            }

            if (! is_dir($full)) {
                continue;
            }

            foreach ($this->scan($base, $full) as $file) {
                $files[$file] = true;
            }
        }

        $found = array_keys($files);
        sort($found);

        return $found;
    }

    /**
     * @return iterable<string>
     */
    private function scan(string $base, string $dir): iterable
    {
        $filter = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            function (SplFileInfo $current) use ($base): bool {
                if ($this->isExcluded($this->relative($base, $current->getPathname()))) {
                    return false;
                }

                if ($current->isDir()) {
                    return ! in_array($current->getFilename(), self::ALWAYS_SKIPPED, true);
                }

                return $this->isPhp($current->getPathname());
            },
        );

        foreach (new RecursiveIteratorIterator($filter) as $file) {
            yield $file->getPathname();
        }
    }

    private function isPhp(string $path): bool
    {
        return pathinfo($path, PATHINFO_EXTENSION) === 'php';
    }

    private function relative(string $base, string $path): string
    {
        return ltrim(substr($path, strlen($base)), '/');
    }

    private function isExcluded(string $relative): bool
    {
        foreach ($this->config->excludes as $pattern) {
            $pattern = trim($pattern, '/');

            if (fnmatch($pattern, $relative) || str_starts_with($relative, $pattern.'/')) {
                return true;
            }
        }

        return false;
    }
}
