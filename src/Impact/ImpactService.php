<?php

declare(strict_types=1);

namespace BlastRadius\Impact;

use BlastRadius\Core\Edge;
use BlastRadius\Core\Node;
use BlastRadius\Static\SymbolTable;

final class ImpactService
{
    /**
     * Impact of everything that changed since `$base` (the merge-base, like a pull request).
     *
     * @param  list<Node>  $nodes  every node in the graph, entry points and tests included
     * @param  list<Edge>  $edges  effective edges
     */
    public static function forBase(string $root, string $base, bool $workingTree, SymbolTable $indexed, array $nodes, array $edges, int $depth): ImpactReport
    {
        $git = new Git($root);
        $mergeBase = $git->mergeBase($base);
        $fileChanges = DiffParser::parse($git->diff($base, $workingTree));
        $changes = ChangeMapper::map($fileChanges, $indexed, $root, fn (string $file) => $git->show($mergeBase, $file));

        return ImpactAnalyzer::analyze($changes, $nodes, $edges, $depth, array_map(fn (FileChange $file) => $file->path, $fileChanges));
    }
}
