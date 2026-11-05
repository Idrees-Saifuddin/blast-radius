<?php

declare(strict_types=1);

namespace BlastRadius\Static;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;

final class TypeNames
{
    /**
     * The class name a declared type points to, or null for builtin, union and
     * intersection types (not resolved yet). Needs NameResolver to have run.
     */
    public static function of(?Node $type, ?string $self): ?string
    {
        if ($type instanceof NullableType) {
            $type = $type->type;
        }

        if (! $type instanceof Name) {
            return null;
        }

        return match ($type->toLowerString()) {
            'self', 'static' => $self,
            'parent' => null,
            default => $type->toString(),
        };
    }
}
