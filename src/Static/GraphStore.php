<?php

declare(strict_types=1);

namespace BlastRadius\Static;

use BlastRadius\Core\Confidence;
use BlastRadius\Core\Edge;
use BlastRadius\Core\EdgeKind;
use BlastRadius\Core\Node;
use BlastRadius\Core\NodeKind;
use PDO;

/**
 * The graph cache: one SQLite file (or `:memory:`) holding, per source file, its symbols,
 * edges and property types, so unchanged files are never analysed twice.
 */
final class GraphStore
{
    private const SCHEMA_VERSION = '2';

    private PDO $pdo;

    public function __construct(string $path)
    {
        if ($path !== ':memory:' && ! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        $this->pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // A cache that can be rebuilt: favour speed over durability.
        $this->pdo->exec('PRAGMA synchronous = OFF');
        $this->pdo->exec('PRAGMA journal_mode = MEMORY');
        $this->migrate();

        if ($this->meta('schema') !== self::SCHEMA_VERSION) {
            foreach (['nodes', 'edges', 'property_types', 'files'] as $table) {
                $this->pdo->exec("DROP TABLE IF EXISTS {$table}");
            }

            $this->pdo->exec('DELETE FROM meta');
            $this->migrate();
            $this->setMeta('schema', self::SCHEMA_VERSION);
        }
    }

    /**
     * Empties the store when it was built for another project root, config or schema version.
     */
    public function prepare(string $configFingerprint): void
    {
        $fingerprint = $configFingerprint.'|'.self::SCHEMA_VERSION;

        if ($this->meta('config') === $fingerprint) {
            return;
        }

        foreach (['nodes', 'edges', 'property_types', 'files'] as $table) {
            $this->pdo->exec("DELETE FROM {$table}");
        }

        $this->pdo->exec("DELETE FROM meta WHERE key != 'schema'");
        $this->setMeta('config', $fingerprint);
    }

    public function transaction(callable $work): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    public function meta(string $key): ?string
    {
        $statement = $this->pdo->prepare('SELECT value FROM meta WHERE key = ?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    public function setMeta(string $key, string $value): void
    {
        $this->pdo->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)')->execute([$key, $value]);
    }

    /**
     * @return array<string, string> path => content hash
     */
    public function fileHashes(): array
    {
        $hashes = [];

        foreach ($this->pdo->query('SELECT path, hash FROM files') ?: [] as $row) {
            $hashes[(string) $row['path']] = (string) $row['hash'];
        }

        return $hashes;
    }

    public function removeFile(string $file): void
    {
        foreach (['nodes', 'edges', 'property_types'] as $table) {
            $this->pdo->prepare("DELETE FROM {$table} WHERE file = ?".($table === 'property_types' ? '' : ' AND layer IS NULL'))->execute([$file]);
        }

        $this->pdo->prepare('DELETE FROM files WHERE path = ?')->execute([$file]);
    }

    /**
     * Stores everything known about a file after it was analysed, replacing older data.
     *
     * @param  list<Node>  $nodes
     * @param  list<Edge>  $edges  hierarchy edges
     * @param  array<string, array<string, string>>  $propertyTypes
     */
    public function replaceFile(string $file, string $hash, ?string $error, array $nodes, array $edges, array $propertyTypes): void
    {
        $this->removeFile($file);
        $this->pdo->prepare('INSERT INTO files (path, hash, error) VALUES (?, ?, ?)')->execute([$file, $hash, $error]);

        $insert = $this->pdo->prepare('INSERT INTO nodes (kind, name, file, start_line, end_line) VALUES (?, ?, ?, ?, ?)');

        foreach ($nodes as $node) {
            $insert->execute([$node->kind->value, $node->name, $node->file, $node->startLine, $node->endLine]);
        }

        $this->insertEdges($edges);

        $insert = $this->pdo->prepare('INSERT OR REPLACE INTO property_types (class, property, type, file) VALUES (?, ?, ?, ?)');

        foreach ($propertyTypes as $class => $types) {
            foreach ($types as $property => $type) {
                $insert->execute([$class, $property, $type, $file]);
            }
        }
    }

    /**
     * @param  list<Edge>  $edges  call edges of one file
     */
    public function replaceCallEdges(string $file, array $edges): void
    {
        $this->pdo->prepare('DELETE FROM edges WHERE file = ? AND is_hierarchy = 0 AND layer IS NULL')->execute([$file]);
        $this->insertEdges($edges);
    }

    /**
     * Replaces everything a named layer contributed, e.g. the entry points and bindings found
     * by booting the host app. Layers are not tied to a source file and are ignored by the
     * resolution fingerprint, so changing them never forces calls to be resolved again.
     *
     * @param  list<Node>  $nodes
     * @param  list<Edge>  $edges
     */
    public function replaceLayer(string $layer, array $nodes, array $edges): void
    {
        $this->transaction(function () use ($layer, $nodes, $edges): void {
            foreach (['nodes', 'edges'] as $table) {
                $this->pdo->prepare("DELETE FROM {$table} WHERE layer = ?")->execute([$layer]);
            }

            $insert = $this->pdo->prepare('INSERT INTO nodes (kind, name, file, start_line, end_line, layer) VALUES (?, ?, ?, ?, ?, ?)');

            foreach ($nodes as $node) {
                $insert->execute([$node->kind->value, $node->name, $node->file, $node->startLine, $node->endLine, $layer]);
            }

            $this->insertEdges($edges, $layer);
        });
    }

    /**
     * Hash of everything call resolution depends on: symbol names, hierarchy edges and
     * property types. Line numbers are left out, so editing a method body does not change it.
     */
    public function resolutionFingerprint(): string
    {
        $hash = hash_init('sha1');

        foreach ([
            'SELECT kind, name FROM nodes WHERE layer IS NULL ORDER BY kind, name',
            'SELECT from_name, to_name, kind FROM edges WHERE is_hierarchy = 1 AND layer IS NULL ORDER BY from_name, to_name, kind',
            'SELECT class, property, type FROM property_types ORDER BY class, property, type',
        ] as $query) {
            foreach ($this->pdo->query($query) ?: [] as $row) {
                hash_update($hash, implode("\0", [$row[0], $row[1], $row[2] ?? '']).PHP_EOL);
            }

            hash_update($hash, '--');
        }

        return hash_final($hash);
    }

    public function table(?IndexStats $stats = null): SymbolTable
    {
        $nodes = [];

        foreach ($this->pdo->query('SELECT kind, name, file, start_line, end_line FROM nodes ORDER BY id') ?: [] as $row) {
            $nodes[] = new Node(NodeKind::from((string) $row['kind']), (string) $row['name'], (string) $row['file'], (int) $row['start_line'], (int) $row['end_line']);
        }

        $edges = [];

        foreach ($this->pdo->query('SELECT from_name, to_name, kind, confidence, file, line FROM edges ORDER BY id') ?: [] as $row) {
            $edges[] = new Edge((string) $row['from_name'], (string) $row['to_name'], EdgeKind::from((string) $row['kind']), Confidence::from((string) $row['confidence']), (string) $row['file'], (int) $row['line']);
        }

        $properties = [];

        foreach ($this->pdo->query('SELECT class, property, type FROM property_types ORDER BY class, property') ?: [] as $row) {
            $properties[(string) $row['class']][(string) $row['property']] ??= (string) $row['type'];
        }

        $errors = [];

        foreach ($this->pdo->query('SELECT path, error FROM files WHERE error IS NOT NULL ORDER BY path') ?: [] as $row) {
            $errors[(string) $row['path']] = (string) $row['error'];
        }

        return new SymbolTable($nodes, $errors, $edges, $properties, $stats);
    }

    /**
     * Where symbols are declared, for names such as `App\Services\Sales::total`.
     *
     * @param  list<string>  $names
     * @return array<string, array{file: string, line: int, end: int}>
     */
    public function nodeLocations(array $names): array
    {
        $found = [];

        foreach (array_chunk($names, 500) as $chunk) {
            $statement = $this->pdo->prepare('SELECT name, file, start_line, end_line FROM nodes WHERE layer IS NULL AND file != \'\' AND name IN ('.implode(',', array_fill(0, count($chunk), '?')).')');
            $statement->execute($chunk);

            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $found[(string) $row['name']] = ['file' => (string) $row['file'], 'line' => (int) $row['start_line'], 'end' => (int) $row['end_line']];
            }
        }

        return $found;
    }

    /**
     * @param  list<Edge>  $edges
     */
    private function insertEdges(array $edges, ?string $layer = null): void
    {
        $insert = $this->pdo->prepare('INSERT INTO edges (from_name, to_name, kind, confidence, file, line, is_hierarchy, layer) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');

        foreach ($edges as $edge) {
            $insert->execute([$edge->from, $edge->to, $edge->kind->value, $edge->confidence->value, $edge->file, $edge->line, (int) $edge->kind->isHierarchy(), $layer]);
        }
    }

    private function migrate(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS files (path TEXT PRIMARY KEY, hash TEXT NOT NULL, error TEXT);
            CREATE TABLE IF NOT EXISTS nodes (
                id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, name TEXT NOT NULL,
                file TEXT NOT NULL, start_line INTEGER NOT NULL, end_line INTEGER NOT NULL, layer TEXT
            );
            CREATE INDEX IF NOT EXISTS nodes_file ON nodes (file);
            CREATE INDEX IF NOT EXISTS nodes_name ON nodes (name);
            CREATE TABLE IF NOT EXISTS edges (
                id INTEGER PRIMARY KEY AUTOINCREMENT, from_name TEXT NOT NULL, to_name TEXT NOT NULL,
                kind TEXT NOT NULL, confidence TEXT NOT NULL, file TEXT NOT NULL, line INTEGER NOT NULL,
                is_hierarchy INTEGER NOT NULL, layer TEXT
            );
            CREATE INDEX IF NOT EXISTS edges_file ON edges (file);
            CREATE INDEX IF NOT EXISTS edges_from ON edges (from_name);
            CREATE INDEX IF NOT EXISTS edges_to ON edges (to_name);
            CREATE TABLE IF NOT EXISTS property_types (
                class TEXT NOT NULL, property TEXT NOT NULL, type TEXT NOT NULL, file TEXT NOT NULL,
                PRIMARY KEY (class, property, file)
            );
        ');
    }
}
