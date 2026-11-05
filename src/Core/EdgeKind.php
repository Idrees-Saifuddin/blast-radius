<?php

declare(strict_types=1);

namespace BlastRadius\Core;

enum EdgeKind: string
{
    case Extends = 'extends';
    case Implements = 'implements';
    case UsesTrait = 'uses-trait';
    case Calls = 'call';
    case Instantiates = 'new';
    case StaticCalls = 'static-call';

    // Added by the Laravel adapter and the dispatch rules.
    case RoutesTo = 'routes-to';
    case Listens = 'listens';
    case Dispatches = 'dispatches';
    case Handles = 'handles';
    case ResolvesTo = 'resolves-to';

    // Observed at run time: the test executed this method.
    case Covers = 'covers';

    public function isHierarchy(): bool
    {
        return match ($this) {
            self::Extends, self::Implements, self::UsesTrait => true,
            default => false,
        };
    }
}
