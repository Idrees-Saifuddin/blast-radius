<?php

declare(strict_types=1);

namespace BlastRadius\Core;

enum NodeKind: string
{
    case Function = 'function';
    case Class_ = 'class';
    case Interface = 'interface';
    case Trait = 'trait';
    case Enum = 'enum';
    case Method = 'method';
    case Closure = 'closure';

    // Entry points, found by booting the host application (Laravel adapter).
    case Route = 'route';
    case Job = 'job';
    case Listener = 'listener';
    case Command = 'command';
    case Schedule = 'schedule';
    case Event = 'event';

    // A test, found by running the host's test suite with a coverage driver.
    case Test = 'test';

    public function isClassLike(): bool
    {
        return match ($this) {
            self::Class_, self::Interface, self::Trait, self::Enum => true,
            default => false,
        };
    }

    public function isEntryPoint(): bool
    {
        return match ($this) {
            self::Route, self::Job, self::Listener, self::Command, self::Schedule => true,
            default => false,
        };
    }
}
