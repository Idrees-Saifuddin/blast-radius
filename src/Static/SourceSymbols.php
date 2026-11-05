<?php

declare(strict_types=1);

namespace BlastRadius\Static;

use BlastRadius\Core\Node;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

final class SourceSymbols
{
    /**
     * Symbols declared in a piece of PHP source, e.g. the old version of a file from git.
     *
     * @return list<Node>
     */
    public static function fromCode(string $code, string $file): array
    {
        try {
            $ast = (new ParserFactory)->createForHostVersion()->parse($code) ?? [];
        } catch (Error) {
            return [];
        }

        $collector = new SymbolCollector($file);
        (new NodeTraverser(new NameResolver, $collector))->traverse($ast);

        return $collector->symbols();
    }
}
