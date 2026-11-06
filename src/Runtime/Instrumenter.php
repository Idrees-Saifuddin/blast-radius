<?php

declare(strict_types=1);

namespace BlastRadius\Runtime;

use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpToken;

/**
 * Rewrites a PHP source file so every method reports to the Tracer:
 *
 *     public function pay() { ORIGINAL }
 *  => public function pay() { $__br = Tracer::enter('A::pay'); try { ORIGINAL }
 *         catch (\Throwable $__be) { Tracer::fail($__br, $__be); throw $__be; }
 *         finally { Tracer::leave($__br); } }
 *
 * Everything is inserted on the line of the braces, so line numbers do not move and errors
 * still point at the right line. `__FILE__` and `__DIR__` are replaced by the original path
 * because the rewritten copy lives somewhere else. Returns null when there is nothing to
 * instrument or the file cannot be parsed; the caller then uses the original file.
 */
final class Instrumenter
{
    public static function transform(string $code, string $file): ?string
    {
        return self::inspect($code, $file)[0];
    }

    /**
     * Like transform(), and says why when there is nothing to return.
     *
     * @return array{string|null, string} the rewritten code, and 'ok'; or null with 'nothing'
     *                                    (no methods to time: an interface, say) or 'parse' (the file does not parse)
     */
    public static function inspect(string $code, string $file): array
    {
        try {
            $ast = (new ParserFactory)->createForHostVersion()->parse($code);
        } catch (Error) {
            return [null, 'parse'];
        }

        if ($ast === null) {
            return [null, 'nothing'];
        }

        $locator = new MethodLocator;
        (new NodeTraverser(new NameResolver, $locator))->traverse($ast);

        if ($locator->methods === []) {
            return [null, 'nothing'];
        }

        $tokens = array_values(PhpToken::tokenize($code));

        /** @var list<array{int, int, string}> $edits position, length to replace, replacement */
        $edits = [];

        foreach ($tokens as $token) {
            if ($token->id === T_FILE) {
                $edits[] = [$token->pos, strlen($token->text), var_export($file, true)];
            } elseif ($token->id === T_DIR) {
                $edits[] = [$token->pos, strlen($token->text), var_export(dirname($file), true)];
            }
        }

        foreach ($locator->methods as [$name, $start, $end]) {
            $open = self::bodyBrace($tokens, $start, $end);

            if ($open === null) {
                continue;
            }

            $edits[] = [$open + 1, 0, '$__br=\\BlastRadius\\Runtime\\Tracer::enter('.var_export($name, true).');try{'];
            $edits[] = [$end, 0, '}catch(\\Throwable $__be){\\BlastRadius\\Runtime\\Tracer::fail($__br,$__be);throw $__be;}finally{\\BlastRadius\\Runtime\\Tracer::leave($__br);}'];
        }

        // Apply from the end of the file backwards so earlier offsets stay valid.
        usort($edits, fn (array $a, array $b) => $b[0] <=> $a[0]);

        foreach ($edits as [$position, $length, $replacement]) {
            $code = substr($code, 0, $position).$replacement.substr($code, $position + $length);
        }

        return [$code, 'ok'];
    }

    /**
     * Offset of the `{` that opens the method body: the first one outside any parentheses,
     * brackets or attributes after the method starts.
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function bodyBrace(array $tokens, int $start, int $end): ?int
    {
        $low = 0;
        $high = count($tokens) - 1;

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($tokens[$middle]->pos < $start) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        $depth = 0;

        for ($i = $low, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->pos > $end) {
                return null;
            }

            if ($token->id === T_ATTRIBUTE || $token->text === '(' || $token->text === '[') {
                $depth++;
            } elseif ($token->text === ')' || $token->text === ']') {
                $depth--;
            } elseif ($depth === 0 && $token->id === ord('{')) {
                return $token->pos;
            }
        }

        return null;
    }
}
