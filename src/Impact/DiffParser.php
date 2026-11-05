<?php

declare(strict_types=1);

namespace BlastRadius\Impact;

final class DiffParser
{
    /**
     * Parses `git diff --unified=0` output into per-file changed line ranges.
     *
     * @return list<FileChange>
     */
    public static function parse(string $diff): array
    {
        $changes = [];
        $old = null;
        $path = null;
        $deleted = false;
        $added = [];
        $removed = [];
        $open = false;

        foreach (preg_split('/\R/', $diff) ?: [] as $line) {
            if (str_starts_with($line, 'diff --git ')) {
                if ($open && $path !== null) {
                    $changes[] = new FileChange($path, $old, $old === null, $deleted, $added, $removed);
                }

                [$open, $old, $path, $deleted, $added, $removed] = [true, null, null, false, [], []];
            } elseif (! $open) {
                continue;
            } elseif (str_starts_with($line, '--- ')) {
                $old = self::path(substr($line, 4));
            } elseif (str_starts_with($line, '+++ ')) {
                $new = self::path(substr($line, 4));
                $deleted = $new === null;
                $path = $new ?? $old;
            } elseif (preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $line, $m) === 1) {
                $oldCount = $m[2] !== '' ? (int) $m[2] : 1;
                $newCount = ($m[4] ?? '') !== '' ? (int) $m[4] : 1;

                if ($oldCount > 0) {
                    $removed[] = [(int) $m[1], (int) $m[1] + $oldCount - 1];
                }

                if ($newCount > 0) {
                    $added[] = [(int) $m[3], (int) $m[3] + $newCount - 1];
                }
            }
        }

        if ($open && $path !== null) {
            $changes[] = new FileChange($path, $old, $old === null, $deleted, $added, $removed);
        }

        return $changes;
    }

    private static function path(string $header): ?string
    {
        $header = trim($header);

        if ($header === '/dev/null') {
            return null;
        }

        return preg_replace('#^[ab]/#', '', trim($header, '"'));
    }
}
