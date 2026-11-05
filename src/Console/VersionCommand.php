<?php

declare(strict_types=1);

namespace BlastRadius\Console;

use BlastRadius\BlastRadius;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'blast:version', description: 'Show the BlastRadius version')]
final class VersionCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('BlastRadius '.BlastRadius::VERSION);

        return Command::SUCCESS;
    }
}
