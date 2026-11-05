<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Feature;

/**
 * The same end-to-end scenarios, with the Laravel app in a `backend/` subfolder of the git
 * repository (a common layout): git reports paths relative to the repository root, and the
 * library must still find the right files.
 */
final class BlastMonorepoTest extends BlastEndToEndTest
{
    protected function subdirectory(): string
    {
        return 'backend';
    }
}
