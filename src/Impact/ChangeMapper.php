<?php

declare(strict_types=1);

namespace BlastRadius\Impact;

use BlastRadius\Core\Node;
use BlastRadius\Core\NodeKind;
use BlastRadius\Static\SourceSymbols;
use BlastRadius\Static\SymbolTable;

/**
 * Maps changed line ranges to the symbols they touch, in both versions of each file: lines
 * that were added or edited are looked up in the new code, removed lines in the old code
 * (so a deleted method is found even though it no longer exists).
 *
 * A symbol seen as deleted in one file and added in another (a moved file or class) is
 * reported as modified, since callers still reach it by the same name.
 */
final class ChangeMapper
{
    private const CODE = [NodeKind::Method, NodeKind::Function, NodeKind::Closure];

    private const CLASS_LIKE = [NodeKind::Class_, NodeKind::Interface, NodeKind::Trait, NodeKind::Enum];

    /**
     * @param  list<FileChange>  $changes
     * @param  callable(string): ?string  $oldSource  old contents of a file by its old path
     * @return list<Change>
     */
    public static function map(array $changes, SymbolTable $table, string $root, callable $oldSource): array
    {
        $byFile = [];

        foreach ($table->nodes as $node) {
            if ($node->file !== '') {
                $byFile[$node->file][] = $node;
            }
        }

        /** @var array<string, array{file: string, kinds: array<string, true>, signature: bool}> $found */
        $found = [];

        foreach ($changes as $change) {
            if (! str_ends_with($change->path, '.php')) {
                continue;
            }

            $file = rtrim($root, '/').'/'.$change->path;
            $new = $change->isDeleted ? [] : ($byFile[$file] ?? []);
            $old = [];

            if (! $change->isNew && $change->oldPath !== null && ($source = $oldSource($change->oldPath)) !== null) {
                $old = SourceSymbols::fromCode($source, $file);
            }

            $newNames = array_flip(array_map(fn (Node $n) => $n->name, $new));
            $oldNames = array_flip(array_map(fn (Node $n) => $n->name, $old));

            foreach ($change->addedRanges as [$from, $to]) {
                foreach (self::touched($new, $from, $to) as $name => $hit) {
                    self::record($found, $name, $change->path, isset($oldNames[$name]) ? Change::MODIFIED : Change::ADDED, $hit['signature']);
                }
            }

            foreach ($change->removedRanges as [$from, $to]) {
                foreach (self::touched($old, $from, $to) as $name => $hit) {
                    self::record($found, $name, $change->path, isset($newNames[$name]) ? Change::MODIFIED : Change::DELETED, $hit['signature']);
                }
            }
        }

        ksort($found);

        return array_map(
            fn (string $name) => new Change($name, self::kindOf($found[$name]['kinds']), $found[$name]['file'], $found[$name]['signature']),
            array_keys($found),
        );
    }

    /**
     * @param  array<string, array{file: string, kinds: array<string, true>, signature: bool}>  $found
     */
    private static function record(array &$found, string $name, string $file, string $kind, bool $signature): void
    {
        $found[$name] ??= ['file' => $file, 'kinds' => [], 'signature' => false];
        $found[$name]['kinds'][$kind] = true;
        $found[$name]['signature'] = $found[$name]['signature'] || $signature;
    }

    /**
     * @param  array<string, true>  $kinds
     */
    private static function kindOf(array $kinds): string
    {
        if (isset($kinds[Change::MODIFIED]) || (isset($kinds[Change::ADDED]) && isset($kinds[Change::DELETED]))) {
            return Change::MODIFIED;
        }

        return array_key_first($kinds) ?? Change::MODIFIED;
    }

    /**
     * The innermost code symbol (method, function, closure) for each line in the range, or the
     * enclosing class when the line is outside every method. `signature` is true when the
     * range edits the symbol's declaration line without rewriting the whole symbol.
     *
     * @param  list<Node>  $nodes
     * @return array<string, array{node: Node, signature: bool}>
     */
    private static function touched(array $nodes, int $from, int $to): array
    {
        $touched = [];

        for ($line = $from; $line <= $to; $line++) {
            $node = self::innermost($nodes, $line, self::CODE);
            $isCode = $node !== null;
            $node ??= self::innermost($nodes, $line, self::CLASS_LIKE);

            if ($node === null) {
                continue;
            }

            // An edit to the declaration line counts as a signature change, unless the whole symbol
            // was rewritten or moved (then it is new code, not an edited signature).
            $wholeSymbol = $from <= $node->startLine && $to >= $node->endLine;

            $touched[$node->name] ??= ['node' => $node, 'signature' => false];
            $touched[$node->name]['signature'] = $touched[$node->name]['signature'] || ($isCode && $line === $node->startLine && ! $wholeSymbol);
        }

        return $touched;
    }

    /**
     * @param  list<Node>  $nodes
     * @param  list<NodeKind>  $kinds
     */
    private static function innermost(array $nodes, int $line, array $kinds): ?Node
    {
        $best = null;

        foreach ($nodes as $node) {
            if (in_array($node->kind, $kinds, true) && $line >= $node->startLine && $line <= $node->endLine && ($best === null || $node->endLine - $node->startLine < $best->endLine - $best->startLine)) {
                $best = $node;
            }
        }

        return $best;
    }
}
