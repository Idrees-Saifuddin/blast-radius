<?php

declare(strict_types=1);

namespace BlastRadius\Laravel\Http;

use BlastRadius\Impact\Git;
use BlastRadius\Impact\ImpactService;
use BlastRadius\Laravel\GraphBuilder;
use BlastRadius\Laravel\GraphSettings;
use BlastRadius\Output\HtmlReport;
use BlastRadius\Output\ReportData;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Serves the report page. It builds the graph from the running app on every load (the cache
 * keeps that fast), so a refresh always shows the code as it is now. Nothing here runs on any
 * other URL.
 */
final class ReportController
{
    public function show(Request $request, Application $app): Response
    {
        $base = $request->query('base');
        $base = is_string($base) && $base !== '' ? $base : null;

        if ($base !== null && ! Git::isSafeRevision($base)) {
            return $this->page(422, 'Invalid "base": use a branch, tag or commit such as main or HEAD~1.');
        }

        [$root, $config, $db] = GraphSettings::resolve($app);

        try {
            $built = (new GraphBuilder($app))->build($root, $config, $db);
            $impact = $base === null ? null : ImpactService::forBase($root, $base, false, $built->indexed, $built->all->nodes, $built->edges, 8);
            $html = HtmlReport::render(ReportData::build($root, $built->all->nodes, $built->edges, max(1, (int) config('blastradius.ui.depth', 6)), $impact, $base, true, (string) config('app.name')));
        } catch (Throwable $e) {
            return $this->page(500, 'BlastRadius could not build the report: '.$e->getMessage());
        }

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex']);
    }

    private function page(int $status, string $message): Response
    {
        return new Response('<!doctype html><meta charset="utf-8"><title>BlastRadius</title><h1>BlastRadius</h1><p>'.e($message).'</p>', $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
