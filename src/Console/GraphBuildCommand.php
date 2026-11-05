<?php

declare(strict_types=1);

namespace BlastRadius\Console;

use BlastRadius\Core\Confidence;
use BlastRadius\Core\Config;
use BlastRadius\Static\SymbolIndexer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'graph:build', description: 'Build or update the code graph cache (.blastradius/graph.sqlite)')]
final class GraphBuildCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Project root', (string) getcwd())
            ->addOption('paths', null, InputOption::VALUE_REQUIRED, 'Comma-separated folders to scan, relative to the root')
            ->addOption('db', null, InputOption::VALUE_REQUIRED, 'Cache file (default: <root>/.blastradius/graph.sqlite)')
            ->addOption('fresh', null, InputOption::VALUE_NONE, 'Delete the cache and rebuild everything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = (string) $input->getOption('path');
        $paths = $input->getOption('paths');
        $config = is_string($paths) && $paths !== '' ? new Config(paths: array_map('trim', explode(',', $paths))) : new Config;

        $db = $input->getOption('db');
        $db = is_string($db) && $db !== '' ? $db : rtrim($root, '/').'/.blastradius/graph.sqlite';

        if ($input->getOption('fresh') && is_file($db)) {
            unlink($db);
        }

        $started = hrtime(true);
        $table = (new SymbolIndexer($config, $db))->index($root);
        $seconds = (hrtime(true) - $started) / 1e9;
        $stats = $table->stats;

        $counts = array_fill_keys(array_column(Confidence::cases(), 'value'), 0);

        foreach ($table->edges as $edge) {
            if (! $edge->kind->isHierarchy()) {
                $counts[$edge->confidence->value]++;
            }
        }

        if ($stats !== null) {
            $output->writeln(sprintf(
                '%d files: %d analysed, %d unchanged, %d removed; calls resolved again in %d%s.',
                $stats->files,
                $stats->parsed,
                $stats->reused,
                $stats->removed,
                $stats->resolved,
                $stats->fullResolution && $stats->resolved > $stats->parsed ? ' (a declaration changed)' : '',
            ));
        }

        $output->writeln(sprintf(
            '%d symbols, %d hierarchy edges, %d call edges (%d CONFIRMED, %d POSSIBLE, %d UNRESOLVED) in %.2fs.',
            count($table->nodes),
            count($table->edges) - array_sum($counts),
            array_sum($counts),
            $counts['CONFIRMED'],
            $counts['POSSIBLE'],
            $counts['UNRESOLVED'],
            $seconds,
        ));
        $output->writeln('Cache: '.OutputFormatter::escape($db));

        foreach ($table->errors as $file => $error) {
            $output->writeln('<comment>'.OutputFormatter::escape("Could not parse {$file}: {$error}").'</comment>');
        }

        return Command::SUCCESS;
    }
}
