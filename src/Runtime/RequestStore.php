<?php

declare(strict_types=1);

namespace BlastRadius\Runtime;

use PDO;

/**
 * The most recent traced requests, in their own SQLite file (separate from the graph cache so
 * recording a request never contends with building the graph).
 */
final class RequestStore
{
    private PDO $pdo;

    public function __construct(string $path)
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        $this->pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // Several requests (a page fires many API calls at once) write at the same time.
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->pdo->exec('PRAGMA synchronous = OFF');
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT, at TEXT NOT NULL, method TEXT NOT NULL, path TEXT NOT NULL,
                route TEXT, status INTEGER NOT NULL, ms REAL NOT NULL, page TEXT, queries INTEGER NOT NULL,
                query_ms REAL NOT NULL, failed_queries INTEGER NOT NULL, errors INTEGER NOT NULL,
                bytes INTEGER, items INTEGER, data TEXT NOT NULL
            )
        ');

        // Older files were created without the page origin.
        $info = $this->pdo->query('PRAGMA table_info(requests)');
        $columns = $info === false ? [] : array_column($info->fetchAll(PDO::FETCH_ASSOC), 'name');

        if (! in_array('page_origin', $columns, true)) {
            $this->pdo->exec('ALTER TABLE requests ADD COLUMN page_origin TEXT');
        }

        $this->pdo->exec('CREATE INDEX IF NOT EXISTS requests_route ON requests (method, route, id)');
    }

    /**
     * Stores a request and, now and then, forgets old ones: first the surplus of any one route (a
     * page polling the same endpoint every few seconds would otherwise push every other request
     * out), then whatever is older than the newest `$keep`. Failed requests are exempt from the
     * per-route limit, so an error is not lost among the polling.
     *
     * @param  array<string, mixed>  $record
     */
    public function add(array $record, int $keep = 300, int $perRoute = 30): int
    {
        /** @var list<array<string, mixed>> $queries */
        $queries = (array) ($record['queries'] ?? []);
        $queryMs = array_sum(array_map(fn (array $q) => (float) $q['ms'], $queries));
        $failedQueries = count(array_filter($queries, fn (array $q) => $q['ok'] === false));
        $errors = count((array) ($record['exceptions'] ?? [])) + ((int) ($record['status'] ?? 200) >= 500 ? 1 : 0);

        $this->pdo->prepare('
            INSERT INTO requests (at, method, path, route, status, ms, page, page_origin, queries, query_ms, failed_queries, errors, bytes, items, data)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $record['at'], $record['method'], $record['path'], $record['route'], $record['status'], $record['ms'], $record['page'], $record['pageOrigin'] ?? null,
            count($queries), round($queryMs, 3), $failedQueries, $errors, $record['bytes'], $record['items'],
            json_encode($record, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES) ?: '{}',
        ]);

        $id = (int) $this->pdo->lastInsertId();

        if ($id % 20 === 0) {
            $this->pdo->prepare('
                DELETE FROM requests
                WHERE status < 400 AND errors = 0 AND failed_queries = 0
                AND (
                    SELECT COUNT(*) FROM requests newer
                    WHERE newer.method = requests.method
                    AND COALESCE(newer.route, newer.path) = COALESCE(requests.route, requests.path)
                    AND newer.id > requests.id
                ) >= CAST(? AS INTEGER)
            ')->execute([$perRoute]);
            $this->pdo->prepare('DELETE FROM requests WHERE id <= ?')->execute([$id - $keep]);
        }

        return $id;
    }

    /**
     * Summaries of requests newer than `$afterId`, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function list(int $afterId = 0, int $limit = 100): array
    {
        $statement = $this->pdo->prepare('
            SELECT id, at, method, path, route, status, ms, page, page_origin, queries, query_ms, failed_queries, errors, bytes, items
            FROM requests WHERE id > ? ORDER BY id DESC LIMIT ?
        ');
        $statement->execute([$afterId, $limit]);

        $rows = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'id' => (int) $row['id'], 'at' => (string) $row['at'], 'method' => (string) $row['method'], 'path' => (string) $row['path'],
                'route' => $row['route'] === null ? null : (string) $row['route'], 'status' => (int) $row['status'], 'ms' => (float) $row['ms'],
                'page' => $row['page'] === null ? null : (string) $row['page'], 'pageOrigin' => $row['page_origin'] === null ? null : (string) $row['page_origin'], 'queries' => (int) $row['queries'], 'queryMs' => (float) $row['query_ms'],
                'failedQueries' => (int) $row['failed_queries'], 'errors' => (int) $row['errors'],
                'bytes' => $row['bytes'] === null ? null : (int) $row['bytes'], 'items' => $row['items'] === null ? null : (int) $row['items'],
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT data FROM requests WHERE id = ?');
        $statement->execute([$id]);
        $data = $statement->fetchColumn();
        $record = is_string($data) ? json_decode($data, true) : null;

        return is_array($record) ? ['id' => $id] + $record : null;
    }

    public function clear(): void
    {
        $this->pdo->exec('DELETE FROM requests');
    }
}
