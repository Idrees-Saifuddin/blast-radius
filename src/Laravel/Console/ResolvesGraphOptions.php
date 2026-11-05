<?php

declare(strict_types=1);

namespace BlastRadius\Laravel\Console;

use BlastRadius\Core\Config;
use BlastRadius\Laravel\GraphSettings;
use Illuminate\Foundation\Application;

/**
 * The options every graph command shares: project root, folders to scan and the cache file.
 */
trait ResolvesGraphOptions
{
    /**
     * @return array{string, Config, string} root, config, cache file
     */
    protected function graphOptions(Application $app): array
    {
        $option = fn (string $name): ?string => is_string($this->option($name)) ? (string) $this->option($name) : null;

        return GraphSettings::resolve($app, $option('path'), $option('paths'), $option('db'));
    }
}
