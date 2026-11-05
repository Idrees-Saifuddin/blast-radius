<?php

declare(strict_types=1);

namespace BlastRadius\Impact;

use BlastRadius\Core\Confidence;
use BlastRadius\Core\Edge;

final class Traversal
{
    /**
     * Everything reachable from `$start` by following call-like edges, breadth first, so each
     * node is reported at its shortest depth. The confidence of a path is its weakest edge.
     *
     * @param  list<Edge>  $edges
     * @return array<string, array{depth: int, confidence: Confidence}>
     */
    public static function forward(array $edges, string $start, int $maxDepth): array
    {
        $outgoing = [];

        foreach ($edges as $edge) {
            if (! $edge->kind->isHierarchy()) {
                $outgoing[$edge->from][] = $edge;
            }
        }

        $reached = [];
        $queue = [[$start, 0, Confidence::Confirmed]];
        $seen = [$start => true];

        while ($queue !== []) {
            [$current, $depth, $confidence] = array_shift($queue);

            if ($depth >= $maxDepth) {
                continue;
            }

            foreach ($outgoing[$current] ?? [] as $edge) {
                if (isset($seen[$edge->to])) {
                    continue;
                }

                $seen[$edge->to] = true;
                $weakest = self::weakest($confidence, $edge->confidence);
                $reached[$edge->to] = ['depth' => $depth + 1, 'confidence' => $weakest];
                $queue[] = [$edge->to, $depth + 1, $weakest];
            }
        }

        return $reached;
    }

    private static function weakest(Confidence $a, Confidence $b): Confidence
    {
        $rank = [Confidence::Confirmed->value => 2, Confidence::Possible->value => 1, Confidence::Unresolved->value => 0];

        return $rank[$a->value] <= $rank[$b->value] ? $a : $b;
    }
}
