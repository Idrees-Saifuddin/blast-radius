<?php

declare(strict_types=1);

namespace BlastRadius\Impact;

use BlastRadius\Core\Confidence;

final class ImpactReport
{
    /**
     * @param  list<Change>  $changes
     * @param  list<array{entry: string, kind: string, depth: int, confidence: Confidence, path: list<string>, tested: bool}>  $entries
     * @param  array<string, string>  $tests  test => file
     * @param  list<string>  $files  every file the diff touched, PHP or not
     */
    public function __construct(
        public readonly array $changes,
        public readonly array $entries,
        public readonly array $tests,
        public readonly array $files = [],
    ) {}

    /**
     * @return list<string> entry points that are reached with CONFIRMED confidence and no covering test
     */
    public function confirmedUntested(): array
    {
        return array_values(array_map(
            fn (array $e) => $e['entry'],
            array_filter($this->entries, fn (array $e) => $e['confidence'] === Confidence::Confirmed && ! $e['tested']),
        ));
    }
}
