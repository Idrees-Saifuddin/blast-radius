<?php

declare(strict_types=1);

namespace BlastRadius\Coverage;

use BlastRadius\Core\Config;
use BlastRadius\Core\NodeKind;
use BlastRadius\Static\GraphStore;
use BlastRadius\Static\SymbolIndexer;
use RuntimeException;

final class CoverageService
{
    public function __construct(
        private readonly Config $config,
        private readonly CoverageRunner $runner,
    ) {}

    /**
     * Runs the test suite once with coverage and stores which tests executed which methods.
     *
     * @param  list<string>  $testDirectories  test folders relative to the root
     * @param  list<string>  $arguments  extra test runner arguments
     * @return array{tests: int, methods: int, coveredMethods: int, exitCode: int, output: string}
     */
    public function build(string $root, string $db, array $testDirectories, array $arguments = []): array
    {
        $table = (new SymbolIndexer($this->config, $db))->index($root);

        $coverageFile = tempnam(sys_get_temp_dir(), 'blast-cov-');

        if ($coverageFile === false) {
            throw new RuntimeException('Could not create a temporary file.');
        }

        unlink($coverageFile);

        try {
            // Coverage is only useful for the code in the graph, so say which folders to cover instead of relying
            // on the project's phpunit.xml: without a filter PHPUnit 9 writes no coverage file at all.
            $filters = [];

            foreach ($this->config->paths as $path) {
                $directory = rtrim($root, '/').'/'.trim($path, '/');

                if (is_dir($directory)) {
                    $filters[] = '--coverage-filter='.$directory;
                }
            }

            $run = $this->runner->run($root, $coverageFile, [...$filters, ...$arguments]);
            $data = CoverageData::read($coverageFile);
        } finally {
            @unlink($coverageFile);
        }

        $map = CoverageMap::build($data['lines'], $data['testIds'], $table, TestFiles::map($root, $testDirectories));

        $store = new GraphStore($db);
        $store->replaceLayer('coverage', $map['nodes'], $map['edges']);
        $store->setMeta('coverage', json_encode(['tests' => count($map['nodes']), 'at' => date('c')], JSON_THROW_ON_ERROR));

        $covered = [];

        foreach ($map['edges'] as $edge) {
            $covered[$edge->to] = true;
        }

        $methods = count(array_filter($table->nodes, fn ($node) => $node->kind === NodeKind::Method));

        return [
            'tests' => count($map['nodes']),
            'methods' => $methods,
            'coveredMethods' => count($covered),
            'exitCode' => $run['exitCode'],
            'output' => $run['output'],
        ];
    }
}
