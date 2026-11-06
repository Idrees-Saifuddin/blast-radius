<?php

declare(strict_types=1);

namespace BlastRadius\Runtime;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Collects everything worth knowing about the request being served: function spans (from the
 * Tracer), database queries with their time, rows and failures, models loaded, exceptions and
 * the size of the response. Static for the same reason as the Tracer.
 */
final class RequestRecorder
{
    private const MAX_QUERIES = 500;

    private const MAX_SPANS_STORED = 6000;

    private static bool $recording = false;

    private static string $root = '';

    private static int $filesBefore = 0;

    private static int $endedAt = -1;

    private static string $startedAt = '';

    /** @var list<array<string, mixed>> */
    private static array $queries = [];

    private static int $lastQuery = -1;

    /** @var array<string, int> */
    private static array $models = [];

    /** @var list<array<string, mixed>> */
    private static array $exceptions = [];

    public static function begin(string $root): void
    {
        self::$recording = true;
        self::$root = rtrim($root, '/');
        self::$filesBefore = count(get_included_files());
        self::$endedAt = -1;
        self::$startedAt = date('c');
        self::$queries = [];
        self::$lastQuery = -1;
        self::$models = [];
        self::$exceptions = [];
        Tracer::begin();
    }

    public static function recording(): bool
    {
        return self::$recording;
    }

    /** A query that ran. Rows are attached right after by rowsReturned(). */
    public static function query(QueryExecuted $event): void
    {
        if (! self::$recording || count(self::$queries) >= self::MAX_QUERIES) {
            self::$lastQuery = -1;

            return;
        }

        $elapsed = (hrtime(true) - Tracer::origin()) / 1e6;

        self::$queries[] = [
            'sql' => substr($event->sql, 0, 2000),
            'kind' => self::kindOf($event->sql),
            'ms' => round($event->time, 3),
            'at' => round(max(0.0, $elapsed - $event->time), 3),
            'ok' => true,
            'rows' => null,
            'rowsKind' => null,
            'error' => null,
            'bindings' => count($event->bindings),
            'connection' => $event->connectionName,
            'span' => Tracer::current(),
            'caller' => self::caller(),
        ];
        self::$lastQuery = count(self::$queries) - 1;
    }

    /**
     * How many rows the query that just ran returned (select) or changed (update, delete).
     */
    public static function rowsReturned(?int $rows, string $kind): void
    {
        if (! self::$recording || self::$lastQuery < 0 || $rows === null) {
            return;
        }

        self::$queries[self::$lastQuery]['rows'] = $rows;
        self::$queries[self::$lastQuery]['rowsKind'] = $kind;
        self::$lastQuery = -1;
    }

    /** A query that threw. Laravel does not announce those, so the connection wrapper reports them. */
    public static function queryFailed(string $sql, int $bindings, int $startedNs, Throwable $e, string $connection): void
    {
        if (! self::$recording || count(self::$queries) >= self::MAX_QUERIES) {
            return;
        }

        $ms = (hrtime(true) - $startedNs) / 1e6;
        $cause = $e->getPrevious() ?? $e;

        self::$queries[] = [
            'sql' => substr($sql, 0, 2000),
            'kind' => self::kindOf($sql),
            'ms' => round($ms, 3),
            'at' => round(max(0.0, (hrtime(true) - Tracer::origin()) / 1e6 - $ms), 3),
            'ok' => false,
            'rows' => null,
            'rowsKind' => null,
            'error' => substr($cause->getMessage(), 0, 500),
            'bindings' => $bindings,
            'connection' => $connection,
            'span' => Tracer::current(),
            'caller' => self::caller(),
        ];
        self::$lastQuery = -1;
    }

    /**
     * Listener for the wildcard `eloquent.retrieved: *` event.
     *
     * @param  array<int, mixed>  $payload
     */
    public static function model(string $event, array $payload): void
    {
        if (! self::$recording || ! isset($payload[0]) || ! is_object($payload[0])) {
            return;
        }

        $class = $payload[0]::class;
        self::$models[$class] = (self::$models[$class] ?? 0) + 1;
    }

    public static function exception(Throwable $e): void
    {
        if (! self::$recording || count(self::$exceptions) >= 20) {
            return;
        }

        self::$exceptions[] = [
            'class' => $e::class,
            'message' => substr($e->getMessage(), 0, 500),
            'file' => self::relative(InstrumentingLoader::originalOf($e->getFile())),
            'line' => $e->getLine(),
            'x' => spl_object_id($e),
            'code' => $e->getCode(),
            'appFrame' => self::applicationFrame($e),
        ];
    }

    /**
     * Where your own code was when the exception happened or was triggered: the first stack
     * frame inside the project. For a database error that is the line that ran the query,
     * not Laravel's internals.
     */
    private static function applicationFrame(Throwable $e): ?string
    {
        $frames = [['file' => $e->getFile(), 'line' => $e->getLine()], ...$e->getTrace()];

        foreach ($frames as $frame) {
            $file = isset($frame['file']) ? InstrumentingLoader::originalOf($frame['file']) : null;

            if ($file !== null && str_starts_with($file, self::$root.'/') && ! str_contains($file, '/vendor/')) {
                return self::relative($file).':'.($frame['line'] ?? 0);
            }
        }

        return null;
    }

    /** The response is ready: this is the request's duration, before terminating work. */
    public static function markEnd(): void
    {
        if (self::$recording && self::$endedAt < 0) {
            self::$endedAt = (int) (hrtime(true) - Tracer::origin());
        }
    }

    /**
     * Stops recording and returns the finished record, ready to be stored.
     *
     * @return array<string, mixed>|null
     */
    public static function finish(Request $request, Response $response): ?array
    {
        if (! self::$recording) {
            return null;
        }

        // Laravel's router turns an exception into a 500 response before any middleware can see
        // it, but it attaches the exception to that response.
        $thrown = property_exists($response, 'exception') ? $response->exception : null;

        if ($thrown instanceof Throwable && ! in_array(spl_object_id($thrown), array_column(self::$exceptions, 'x'), true)) {
            self::exception($thrown);
        }

        self::$recording = false;
        $trace = Tracer::end();
        $duration = self::$endedAt >= 0 ? self::$endedAt : $trace['endedAt'];

        $spans = [];

        foreach (array_slice($trace['spans'], 0, self::MAX_SPANS_STORED) as $i => $span) {
            $spans[] = [
                'i' => $i,
                'n' => $span['n'],
                'p' => $span['p'],
                's' => round($span['s'] / 1e6, 3),
                'ms' => round(($span['e'] - $span['s']) / 1e6, 3),
                'f' => $span['f'] === null ? null : [
                    'class' => $span['f']['class'],
                    'message' => $span['f']['message'],
                    'file' => self::relative(InstrumentingLoader::originalOf($span['f']['file'])),
                    'line' => $span['f']['line'],
                    'x' => $span['f']['x'],
                ],
            ];
        }

        [$bytes, $items] = self::responseSize($response);
        [$page, $pageOrigin] = self::page($request);
        $route = $request->route();

        return [
            'at' => self::$startedAt,
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'uri' => $request->getRequestUri(),
            'route' => is_object($route) && method_exists($route, 'uri') ? '/'.ltrim((string) $route->uri(), '/') : null,
            'action' => is_object($route) && method_exists($route, 'getActionName') ? (string) $route->getActionName() : null,
            'status' => $response->getStatusCode(),
            'ms' => round($duration / 1e6, 3),
            'memory' => round(memory_get_peak_usage(true) / 1048576, 1),
            'page' => $page,
            'pageOrigin' => $pageOrigin,
            'bytes' => $bytes,
            'items' => $items,
            'queries' => self::$queries,
            'models' => self::$models,
            'exceptions' => self::$exceptions,
            'spans' => $spans,
            'dropped' => $trace['dropped'] + max(0, count($trace['spans']) - self::MAX_SPANS_STORED),
            'files' => self::loadedFiles(),
            'timing' => self::timing(),
        ];
    }

    /**
     * Which classes were timed and which ran as they are, so a trace with gaps does not pass for
     * a complete one. Two kinds of "as they are": `untimed` are real gaps (the file could not be
     * rewritten or its copy was invalid); `early` classes were loaded before timing started (service
     * providers and the like), which is normal in every app and only worth a quiet note.
     *
     * @return array{timed: int, untimed: list<array{file: string, reason: string}>, untimedTotal: int, early: list<array{file: string, reason: string}>, earlyTotal: int}|null
     */
    private static function timing(): ?array
    {
        $coverage = InstrumentingLoader::coverage();

        if ($coverage === null) {
            return null;
        }

        $untimed = [];
        $early = [];

        foreach ($coverage['untimed'] as $entry) {
            $entry = ['file' => self::relative($entry['file']), 'reason' => $entry['reason']];

            if ($entry['reason'] === 'early') {
                $early[] = $entry;
            } else {
                $untimed[] = $entry;
            }
        }

        return [
            'timed' => $coverage['timed'],
            'untimed' => array_slice($untimed, 0, 50), 'untimedTotal' => count($untimed),
            'early' => array_slice($early, 0, 50), 'earlyTotal' => count($early),
        ];
    }

    private static function kindOf(string $sql): string
    {
        $word = strtolower(strtok(ltrim($sql), " \t\n(") ?: '');

        return in_array($word, ['select', 'insert', 'update', 'delete'], true) ? $word : 'other';
    }

    /** The first application frame on the stack: the line of your code that caused the query. */
    private static function caller(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null) {
                continue;
            }

            $file = InstrumentingLoader::originalOf($file);

            if (str_starts_with($file, self::$root.'/') && ! str_contains($file, '/vendor/')) {
                return self::relative($file).':'.($frame['line'] ?? 0);
            }
        }

        return null;
    }

    private static function relative(string $file): string
    {
        return str_starts_with($file, self::$root.'/') ? substr($file, strlen(self::$root) + 1) : $file;
    }

    /**
     * The page that made this (usually API) request, and the address it was served from.
     *
     * The path comes from the `X-BlastRadius-Page` header if the frontend sends one, otherwise
     * from `Referer`. Browsers send only the site address (no path) in `Referer` when the page
     * and the API are on different addresses (page on :3000, API on :80), so a bare address
     * across origins means "the page is unknown", not "the home page".
     *
     * @return array{string|null, string|null} page path (no query string), page origin
     */
    private static function page(Request $request): array
    {
        $referer = $request->headers->get('referer');
        $parts = is_string($referer) && $referer !== '' ? parse_url($referer) : false;
        $origin = null;

        if (is_array($parts) && isset($parts['host'])) {
            $origin = ($parts['scheme'] ?? 'http').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        }

        $explicit = $request->headers->get('x-blastradius-page');

        if (is_string($explicit) && $explicit !== '') {
            $path = parse_url($explicit, PHP_URL_PATH);

            return [is_string($path) && $path !== '' ? $path : '/', $origin];
        }

        // A server-rendered page opened from a link: its Referer is the page the user came
        // from, but it is a page of its own, not a call made by that page.
        if (self::isPageLoad($request)) {
            return ['/'.ltrim($request->path(), '/'), $request->getSchemeAndHttpHost()];
        }

        if (! is_array($parts) || $origin === null) {
            return [null, null];
        }

        $path = $parts['path'] ?? '/';

        if ($path === '/' && ! isset($parts['query']) && $origin !== $request->getSchemeAndHttpHost()) {
            return [null, $origin];
        }

        return [$path, $origin];
    }

    /**
     * A GET the browser made to show a whole page (typing the address, a link, a redirect),
     * as opposed to fetch/XHR, Livewire, assets or a frame. Browsers say so in Sec-Fetch-*;
     * where they don't (plain http on a LAN address) an HTML Accept header without the AJAX
     * markers is the same signal. Form posts stay with the page that holds the form.
     */
    private static function isPageLoad(Request $request): bool
    {
        if ($request->method() !== 'GET') {
            return false;
        }

        $mode = $request->headers->get('sec-fetch-mode');

        if (is_string($mode) && $mode !== '') {
            return $mode === 'navigate' && in_array($request->headers->get('sec-fetch-dest'), [null, '', 'document'], true);
        }

        if ($request->headers->has('x-requested-with') || $request->headers->has('x-livewire')) {
            return false;
        }

        return str_starts_with(strtolower((string) $request->headers->get('accept')), 'text/html');
    }

    /**
     * @return array{int|null, int|null} response size in bytes, and the number of records when it is a JSON list
     */
    private static function responseSize(Response $response): array
    {
        $content = $response->getContent();

        if (! is_string($content)) {
            return [null, null];
        }

        $bytes = strlen($content);

        if ($bytes === 0 || $bytes > 2_000_000 || ! str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            return [$bytes, null];
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            return [$bytes, null];
        }

        if (array_is_list($decoded)) {
            return [$bytes, count($decoded)];
        }

        foreach (['data', 'items', 'results'] as $key) {
            $value = $decoded[$key] ?? null;

            if (is_array($value) && array_is_list($value)) {
                return [$bytes, count($value)];
            }

            // Laravel paginators and API resources nest the list one level deeper.
            if (is_array($value) && isset($value['data']) && is_array($value['data']) && array_is_list($value['data'])) {
                return [$bytes, count($value['data'])];
            }
        }

        return [$bytes, null];
    }

    /**
     * @return list<string> project files loaded while this request ran, relative to the root
     */
    private static function loadedFiles(): array
    {
        $files = [];

        foreach (array_slice(get_included_files(), self::$filesBefore) as $file) {
            $file = InstrumentingLoader::originalOf($file);

            if (str_starts_with($file, self::$root.'/') && ! str_contains($file, '/vendor/') && ! str_contains($file, '/storage/')) {
                $files[self::relative($file)] = true;
            }
        }

        return array_slice(array_keys($files), 0, 300);
    }
}
