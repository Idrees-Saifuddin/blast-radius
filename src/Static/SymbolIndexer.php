<?php

declare(strict_types=1);

namespace BlastRadius\Static;

use BlastRadius\Core\Config;
use BlastRadius\Core\NodeKind;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Builds the code graph in two passes: symbols and hierarchy per file, then calls per file
 * (which need the whole hierarchy). With a store path the result is cached in SQLite and
 * only changed files are analysed again.
 */
final class SymbolIndexer
{
    private readonly Parser $parser;

    public function __construct(
        private readonly Config $config = new Config,
        private readonly ?string $storePath = null,
    ) {
        $this->parser = (new ParserFactory)->createForHostVersion();
    }

    public function index(string $basePath): SymbolTable
    {
        $root = realpath($basePath) ?: $basePath;
        $files = (new FileFinder($this->config))->find($basePath);

        $store = new GraphStore($this->storePath ?? ':memory:');
        $store->prepare(sha1(json_encode([$root, $this->config->paths, $this->config->excludes, $this->config->possibleFanOut], JSON_THROW_ON_ERROR)));

        return $store->transaction(function () use ($store, $files, $root): SymbolTable {
            $known = $store->fileHashes();
            $failed = [];

            // Pass 1: symbols, hierarchy edges and property types, for new or changed files.
            $asts = [];
            $hashes = [];

            foreach ($files as $file) {
                $code = file_get_contents($file);
                $hashes[$file] = $code === false ? '' : sha1($code);

                if (($known[$file] ?? null) === $hashes[$file]) {
                    continue;
                }

                $parsed = $this->analyse($file, $code, $hashes[$file], $store);

                if ($parsed === null) {
                    $failed[$file] = true;
                } else {
                    $asts[$file] = $parsed;
                }
            }

            $removed = array_diff(array_keys($known), $files);

            foreach ($removed as $file) {
                $store->removeFile($file);
            }

            // Pass 2: calls. Only changed files, unless a declaration changed.
            $fingerprint = $store->resolutionFingerprint();
            $full = $store->meta('fingerprint') !== $fingerprint;
            $table = $store->table();
            $hierarchy = new Hierarchy($table);
            $functions = [];

            foreach ($table->nodes as $node) {
                if ($node->kind === NodeKind::Function) {
                    $functions[$node->name] = true;
                }
            }

            $resolved = 0;

            foreach ($files as $file) {
                if (isset($failed[$file]) || (! $full && ! isset($asts[$file]))) {
                    continue;
                }

                $ast = $asts[$file] ?? $this->resolvedAst($file);

                if ($ast === null) {
                    continue;
                }

                $calls = new CallCollector($file, $hierarchy, $functions, $this->config->possibleFanOut, $root);
                (new NodeTraverser($calls))->traverse($ast);
                $store->replaceCallEdges($file, $calls->edges());
                $resolved++;
            }

            $store->setMeta('fingerprint', $fingerprint);

            return $store->table(new IndexStats(
                files: count($files),
                parsed: count($asts) + count($failed),
                reused: count($files) - count($asts) - count($failed),
                removed: count($removed),
                resolved: $resolved,
                fullResolution: $full,
            ));
        });
    }

    /**
     * Pass 1 for one file. Returns the name-resolved AST, or null when the file cannot be parsed.
     *
     * @return array<Node>|null
     */
    private function analyse(string $file, string|false $code, string $hash, GraphStore $store): ?array
    {
        if ($code === false) {
            $store->replaceFile($file, $hash, 'File cannot be read.', [], [], []);

            return null;
        }

        try {
            $ast = $this->parser->parse($code) ?? [];
        } catch (Error $e) {
            $store->replaceFile($file, $hash, $e->getMessage(), [], [], []);

            return null;
        }

        $collector = new SymbolCollector($file);
        $ast = (new NodeTraverser(new NameResolver, $collector))->traverse($ast);
        $store->replaceFile($file, $hash, null, $collector->symbols(), $collector->edges(), $collector->propertyTypes());

        return $ast;
    }

    /**
     * @return array<Node>|null
     */
    private function resolvedAst(string $file): ?array
    {
        $code = file_get_contents($file);

        if ($code === false) {
            return null;
        }

        try {
            return (new NodeTraverser(new NameResolver))->traverse($this->parser->parse($code) ?? []);
        } catch (Error) {
            return null;
        }
    }
}
