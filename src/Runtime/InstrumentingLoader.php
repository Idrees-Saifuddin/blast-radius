<?php

declare(strict_types=1);

namespace BlastRadius\Runtime;

use Composer\Autoload\ClassLoader;
use ParseError;

/**
 * An autoloader that runs before Composer's: for classes that live in the configured folders
 * of the project it loads an instrumented copy (see Instrumenter) instead of the original.
 * Anything it cannot handle falls through to Composer, so the worst case is "not timed".
 * Meant for local development only.
 */
final class InstrumentingLoader
{
    /** @var array<string, string> instrumented copy => original file */
    private static array $originals = [];

    private static bool $registered = false;

    /**
     * @param  list<string>  $paths  folders to time, relative to the root (usually `app`)
     */
    public static function register(string $root, array $paths, string $cacheDir): void
    {
        if (self::$registered) {
            return;
        }

        $composer = null;

        foreach (spl_autoload_functions() as $loader) {
            if (is_array($loader) && $loader[0] instanceof ClassLoader) {
                $composer = $loader[0];

                break;
            }
        }

        if ($composer === null) {
            return;
        }

        self::$registered = true;
        $root = rtrim(realpath($root) ?: $root, '/');

        spl_autoload_register(static function (string $class) use ($composer, $root, $paths, $cacheDir): void {
            $file = $composer->findFile($class);
            $real = $file === false ? false : realpath($file);

            if ($real === false || ! self::shouldInstrument($real, $root, $paths)) {
                return;
            }

            $copy = self::compiled($real, $cacheDir);

            if ($copy === null) {
                return;
            }

            try {
                (static function (string $path): void {
                    include $path;
                })($copy);
            } catch (ParseError) {
                // Our rewrite produced invalid code for this file: forget it and let Composer load the original.
                @unlink($copy);
                unset(self::$originals[$copy]);
            }
        }, true, true);
    }

    /** The project file an instrumented copy was made from, or the path itself. */
    public static function originalOf(string $file): string
    {
        return self::$originals[$file] ?? $file;
    }

    /**
     * @param  list<string>  $paths
     */
    private static function shouldInstrument(string $file, string $root, array $paths): bool
    {
        if (! str_starts_with($file, $root.'/') || str_contains($file, '/vendor/')) {
            return false;
        }

        $relative = substr($file, strlen($root) + 1);

        foreach ($paths as $path) {
            if (str_starts_with($relative, trim($path, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    private static function compiled(string $file, string $cacheDir): ?string
    {
        $code = @file_get_contents($file);

        if ($code === false) {
            return null;
        }

        $prefix = md5($file);
        $target = $cacheDir.'/'.$prefix.'-'.md5($code).'.php';

        if (! is_file($target)) {
            $transformed = Instrumenter::transform($code, $file);

            if ($transformed === null || (! is_dir($cacheDir) && ! @mkdir($cacheDir, 0777, true) && ! is_dir($cacheDir))) {
                return null;
            }

            $temporary = $target.'.'.uniqid('', true).'.tmp';

            if (@file_put_contents($temporary, $transformed) === false || ! @rename($temporary, $target)) {
                @unlink($temporary);

                return null;
            }

            // Older versions of the same file are no longer needed.
            foreach (glob($cacheDir.'/'.$prefix.'-*.php') ?: [] as $old) {
                if ($old !== $target) {
                    @unlink($old);
                }
            }
        }

        self::$originals[$target] = $file;

        return $target;
    }
}
