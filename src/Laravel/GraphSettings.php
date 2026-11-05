<?php

declare(strict_types=1);

namespace BlastRadius\Laravel;

use BlastRadius\Core\Config;
use Illuminate\Contracts\Foundation\Application;

/**
 * Where to look and where to cache, from the config file, with overrides for the commands.
 */
final class GraphSettings
{
    /**
     * A path inside the storage folder. Built by hand: on Laravel 8 `storagePath($path)` ignores
     * its argument and returns the storage folder itself.
     */
    private static function storage(Application $app, string $path): string
    {
        return rtrim($app->storagePath(), '/\\').'/'.ltrim($path, '/');
    }

    public static function root(Application $app): string
    {
        $configured = config('blastradius.root');
        $root = is_string($configured) && $configured !== '' ? $configured : $app->basePath();

        return realpath($root) ?: $root;
    }

    public static function requestStorePath(Application $app): string
    {
        $store = config('blastradius.runtime.store');

        return is_string($store) && $store !== '' ? $store : self::storage($app, 'framework/cache/blastradius/requests.sqlite');
    }

    public static function compiledPath(Application $app): string
    {
        return self::storage($app, 'framework/cache/blastradius/compiled');
    }

    /**
     * @return array{string, Config, string} project root, scan config, cache file
     */
    public static function resolve(Application $app, ?string $path = null, ?string $paths = null, ?string $db = null): array
    {
        $configured = config('blastradius.root');
        $root = $path ?: (is_string($configured) && $configured !== '' ? $configured : $app->basePath());
        $root = realpath($root) ?: $root;

        $config = new Config(
            paths: $paths !== null && $paths !== '' ? array_map('trim', explode(',', $paths)) : array_values(array_map('strval', (array) config('blastradius.paths'))),
            excludes: array_values(array_map('strval', (array) config('blastradius.excludes'))),
            possibleFanOut: (int) config('blastradius.possible_fan_out', 10),
        );

        $cache = config('blastradius.cache');
        $db = $db !== null && $db !== '' ? $db : (is_string($cache) && $cache !== '' ? $cache : self::storage($app, 'framework/cache/blastradius/graph.sqlite'));

        return [$root, $config, $db];
    }
}
