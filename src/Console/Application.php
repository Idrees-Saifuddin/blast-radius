<?php

declare(strict_types=1);

namespace BlastRadius\Console;

use BlastRadius\BlastRadius;
use Symfony\Component\Console\Application as SymfonyApplication;

final class Application extends SymfonyApplication
{
    public function __construct()
    {
        parent::__construct('BlastRadius', BlastRadius::VERSION);

        $this->add(new VersionCommand);
        $this->add(new HierarchyCommand);
        $this->add(new GraphBuildCommand);
    }
}
