<?php

declare(strict_types=1);

namespace BlastRadius\Console;

use BlastRadius\Core\Config;
use BlastRadius\Output\HierarchyRenderer;
use BlastRadius\Static\Hierarchy;
use BlastRadius\Static\SymbolIndexer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'blast:hierarchy', description: 'Show the class hierarchy (extends, implements, traits)')]
final class HierarchyCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('class', InputArgument::OPTIONAL, 'Fully qualified class, interface or trait; omit to list every tree')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Project root', (string) getcwd())
            ->addOption('paths', null, InputOption::VALUE_REQUIRED, 'Comma-separated folders to scan, relative to the root');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $paths = $input->getOption('paths');
        $config = is_string($paths) && $paths !== '' ? new Config(paths: array_map('trim', explode(',', $paths))) : new Config;

        $table = (new SymbolIndexer($config))->index((string) $input->getOption('path'));
        $hierarchy = new Hierarchy($table);
        $renderer = new HierarchyRenderer($hierarchy);

        $class = $input->getArgument('class');

        if (is_string($class)) {
            $class = ltrim($class, '\\');

            if (! $hierarchy->knows($class)) {
                $output->writeln('<error>'.OutputFormatter::escape("Unknown type {$class}.").'</error>');

                return Command::FAILURE;
            }

            $this->print($output, 'Extends / implements / uses:', $renderer->ancestors($class));
            $output->writeln('');
            $this->print($output, 'Extended / implemented / used by:', $renderer->descendants($class));

            return Command::SUCCESS;
        }

        foreach ($hierarchy->roots() as $root) {
            $this->print($output, null, $renderer->descendants($root));
        }

        foreach ($table->errors as $file => $error) {
            $output->writeln('<comment>'.OutputFormatter::escape("Could not parse {$file}: {$error}").'</comment>');
        }

        return Command::SUCCESS;
    }

    /**
     * @param  list<string>  $lines
     */
    private function print(OutputInterface $output, ?string $title, array $lines): void
    {
        if ($title !== null) {
            $output->writeln($title);
        }

        foreach ($lines as $line) {
            $output->writeln(OutputFormatter::escape($line));
        }
    }
}
