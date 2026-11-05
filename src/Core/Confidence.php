<?php

declare(strict_types=1);

namespace BlastRadius\Core;

enum Confidence: string
{
    case Confirmed = 'CONFIRMED';
    case Possible = 'POSSIBLE';
    case Unresolved = 'UNRESOLVED';
}
