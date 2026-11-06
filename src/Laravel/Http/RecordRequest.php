<?php

declare(strict_types=1);

namespace BlastRadius\Laravel\Http;

use BlastRadius\Laravel\GraphSettings;
use BlastRadius\Runtime\RequestRecorder;
use BlastRadius\Runtime\RequestStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Global middleware (local development only): records each request. The recording is saved in
 * terminate(), after the response was sent, so it never slows the response down. If saving
 * fails the request is unaffected.
 */
final class RecordRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (self::ignored($request)) {
            return $next($request);
        }

        RequestRecorder::begin(GraphSettings::root(app()));

        try {
            return $next($request);
        } catch (Throwable $e) {
            RequestRecorder::exception($e);

            throw $e;
        } finally {
            RequestRecorder::markEnd();
        }
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! RequestRecorder::recording()) {
            return;
        }

        $record = RequestRecorder::finish($request, $response);

        if ($record === null) {
            return;
        }

        try {
            (new RequestStore(GraphSettings::requestStorePath(app())))->add(
                $record,
                max(20, (int) config('blastradius.runtime.keep', 300)),
                max(5, (int) config('blastradius.runtime.keep_per_route', 30)),
            );
        } catch (Throwable) {
            // Recording is a convenience; it must never break the application.
        }
    }

    private static function ignored(Request $request): bool
    {
        if ($request->isMethod('OPTIONS')) {
            return true;
        }

        $path = ltrim($request->path(), '/');
        $ui = trim((string) config('blastradius.ui.path', 'blast-radius'), '/');

        if ($path === $ui || str_starts_with($path, $ui.'/')) {
            return true;
        }

        foreach ((array) config('blastradius.runtime.except', []) as $pattern) {
            if (is_string($pattern) && fnmatch(trim($pattern, '/'), $path)) {
                return true;
            }
        }

        return false;
    }
}
