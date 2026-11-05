<?php

declare(strict_types=1);

namespace BlastRadius\Laravel\Http;

use BlastRadius\Laravel\GraphSettings;
use BlastRadius\Runtime\RequestStore;
use BlastRadius\Static\GraphStore;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The JSON the live view polls: summaries of recent requests, and one request in full.
 */
final class RequestsController
{
    public function index(Request $request, Application $app): JsonResponse
    {
        $store = new RequestStore(GraphSettings::requestStorePath($app));
        $after = max(0, (int) $request->query('after', '0'));

        return $this->json(['requests' => $store->list($after, 100)]);
    }

    public function show(int $id, Application $app): JsonResponse
    {
        $record = (new RequestStore(GraphSettings::requestStorePath($app)))->find($id);

        if ($record === null) {
            return $this->json(['message' => 'Request not found: it may have been pruned.'], 404);
        }

        $root = GraphSettings::root($app);
        $locations = [];

        try {
            [, , $db] = GraphSettings::resolve($app);

            if (is_file($db)) {
                $names = array_values(array_unique(array_map(fn (array $span) => (string) $span['n'], (array) $record['spans'])));

                foreach ((new GraphStore($db))->nodeLocations($names) as $name => $where) {
                    $file = $where['file'];
                    $locations[$name] = ['file' => str_starts_with($file, $root.'/') ? substr($file, strlen($root) + 1) : $file, 'line' => $where['line'], 'end' => $where['end']];
                }
            }
        } catch (Throwable) {
            // Locations are a nicety; the timeline works without them.
        }

        $record['locations'] = $locations;

        return $this->json($record);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex'], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
    }
}
