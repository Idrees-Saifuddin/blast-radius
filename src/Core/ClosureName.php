<?php

declare(strict_types=1);

namespace BlastRadius\Core;

final class ClosureName
{
    /**
     * Stable name of a closure, shared by the call collector and the Laravel adapter so a
     * closure route or listener finds the calls made inside it: `closure@routes/web.php:12`.
     */
    public static function for(string $root, string $file, int $line): string
    {
        $root = rtrim($root, '/');

        if ($root !== '' && str_starts_with($file, $root.'/')) {
            $file = substr($file, strlen($root) + 1);
        }

        return "closure@{$file}:{$line}";
    }
}
