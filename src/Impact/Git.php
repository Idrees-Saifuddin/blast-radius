<?php

declare(strict_types=1);

namespace BlastRadius\Impact;

use RuntimeException;

final class Git
{
    public function __construct(private readonly string $root) {}

    /**
     * A branch, tag or commit expression. Anything starting with `-` could be read by git as an
     * option, so it is refused; that matters when the value comes from a URL.
     */
    public static function isSafeRevision(string $revision): bool
    {
        return preg_match('#^[A-Za-z0-9][A-Za-z0-9._/~^@{}-]*$#', $revision) === 1;
    }

    /**
     * Changes between the merge-base of `$base` and HEAD (the pull request), optionally
     * including uncommitted work.
     */
    public function diff(string $base, bool $includeWorkingTree = false): string
    {
        // --relative: paths relative to the project root, even when it is a subfolder of the git repository
        return $this->run(['diff', '--unified=0', '--no-color', '--no-ext-diff', '--no-renames', '--relative', $includeWorkingTree ? $this->mergeBase($base) : $this->mergeBase($base).'...HEAD']);
    }

    public function mergeBase(string $base): string
    {
        $this->assertSafe($base);

        return trim($this->run(['merge-base', $base, 'HEAD']));
    }

    public function show(string $revision, string $path): ?string
    {
        $this->assertSafe($revision);

        try {
            // `./` makes the path relative to the project root instead of the repository root
            return $this->run(['show', $revision.':./'.$path]);
        } catch (RuntimeException) {
            return null;
        }
    }

    private function assertSafe(string $revision): void
    {
        if (! self::isSafeRevision($revision)) {
            throw new RuntimeException("Invalid revision: {$revision}");
        }
    }

    /**
     * @param  list<string>  $arguments
     */
    private function run(array $arguments): string
    {
        $process = proc_open(['git', '-C', $this->root, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start git.');
        }

        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);

        if (proc_close($process) !== 0) {
            throw new RuntimeException(trim($error) !== '' ? trim($error) : 'git failed.');
        }

        return $output;
    }
}
