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
 *
 * It also keeps count of what ran untimed and why (coverage()), so a trace that is missing a
 * class cannot be mistaken for a complete one.
 */
final class InstrumentingLoader
{
    /** @var array<string, string> instrumented copy => original file */
    private static array $originals = [];

    /** @var array<string, string> original file => why it ran untimed: parse, invalid, unwritable, unreadable */
    private static array $skipped = [];

    private static bool $registered = false;

    private static string $root = '';

    /** @var list<string> */
    private static array $paths = [];

    private static string $cacheDir = '';

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
        self::$root = $root;
        self::$paths = $paths;
        self::$cacheDir = $cacheDir;

        spl_autoload_register(static function (string $class) use ($composer, $root, $paths, $cacheDir): void {
            $file = $composer->findFile($class);
            $real = $file === false ? false : realpath($file);

            if ($real === false || ! self::shouldInstrument($real, $root, $paths)) {
                return;
            }

            $copy = self::compiled($real, $cacheDir, $verdict);

            if ($copy === null) {
                if ($verdict !== 'nothing') {
                    self::$skipped[$real] = (string) $verdict;
                }

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
                self::$skipped[$real] = 'invalid';
            }
        }, true, true);
    }

    /** The project file an instrumented copy was made from, or the path itself. */
    public static function originalOf(string $file): string
    {
        return self::$originals[$file] ?? $file;
    }

    /**
     * How completely the project's classes were timed so far: how many ran as instrumented
     * copies, and the ones that ran as they are, with the reason:
     *
     *  - early: loaded before timing started (a service provider, say), so it was never offered to us
     *  - parse: the rewriter could not read the file
     *  - invalid: the rewritten copy was not valid PHP
     *  - unwritable / unreadable: the cache folder or the file could not be used
     *
     * Files with nothing to time (interfaces, classes without methods) are in neither list.
     *
     * @return array{timed: int, untimed: list<array{file: string, reason: string}>}|null null when timing is not set up
     */
    public static function coverage(): ?array
    {
        if (! self::$registered) {
            return null;
        }

        $timed = 0;
        $untimed = [];

        foreach (get_included_files() as $included) {
            if (isset(self::$originals[$included])) {
                $timed++;

                continue;
            }

            if (! self::shouldInstrument($included, self::$root, self::$paths)) {
                continue;
            }

            $verdict = self::$skipped[$included] ?? null;

            if ($verdict === null) {
                // Never went through the loader. Ask the same question it would have asked.
                self::compiled($included, self::$cacheDir, $verdict);
                $verdict = $verdict === 'ok' ? 'early' : $verdict;
            }

            if ($verdict !== 'nothing') {
                $untimed[] = ['file' => $included, 'reason' => (string) $verdict];
            }
        }

        return ['timed' => $timed, 'untimed' => $untimed];
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

    /**
     * The instrumented copy of a file, made now if needed, or null when it runs as it is.
     * `$verdict` says which: ok, nothing (no methods to time), parse, unwritable or unreadable.
     * A file with nothing to time (or that cannot be read by the rewriter) leaves a small
     * `.skip` note next to the copies, so it is not parsed again on every request.
     *
     * @param-out string $verdict
     */
    private static function compiled(string $file, string $cacheDir, ?string &$verdict = null): ?string
    {
        $code = @file_get_contents($file);

        if ($code === false) {
            $verdict = 'unreadable';

            return null;
        }

        $base = $cacheDir.'/'.md5($file).'-'.md5($code);
        $target = $base.'.php';
        $note = $base.'.skip';

        if (is_file($target)) {
            $verdict = 'ok';
            self::$originals[$target] = $file;

            return $target;
        }

        $known = is_file($note) ? trim((string) @file_get_contents($note)) : '';

        if ($known === 'nothing' || $known === 'parse') {
            $verdict = $known;

            return null;
        }

        [$transformed, $verdict] = Instrumenter::inspect($code, $file);

        if (! is_dir($cacheDir) && ! @mkdir($cacheDir, 0777, true) && ! is_dir($cacheDir)) {
            $verdict = $transformed === null ? $verdict : 'unwritable';

            return null;
        }

        if ($transformed === null) {
            self::write($note, $verdict);
            self::forgetOlder($cacheDir, md5($file), $note);

            return null;
        }

        if (! self::write($target, $transformed)) {
            $verdict = 'unwritable';

            return null;
        }

        self::forgetOlder($cacheDir, md5($file), $target);
        self::$originals[$target] = $file;

        return $target;
    }

    private static function write(string $path, string $contents): bool
    {
        $temporary = $path.'.'.uniqid('', true).'.tmp';

        if (@file_put_contents($temporary, $contents) === false || ! @rename($temporary, $path)) {
            @unlink($temporary);

            return false;
        }

        return true;
    }

    /** Older versions of the same file are no longer needed. */
    private static function forgetOlder(string $cacheDir, string $prefix, string $keep): void
    {
        foreach ([...(glob($cacheDir.'/'.$prefix.'-*.php') ?: []), ...(glob($cacheDir.'/'.$prefix.'-*.skip') ?: [])] as $old) {
            if ($old !== $keep) {
                @unlink($old);
            }
        }
    }
}
