<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Unit\Runtime;

use BlastRadius\Runtime\RequestStore;
use PHPUnit\Framework\TestCase;

final class RequestStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/blast-store-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    /**
     * @return array<string, mixed>
     */
    private static function request(string $route, int $status = 200): array
    {
        return [
            'at' => '2026-01-01T00:00:00+00:00', 'method' => 'GET', 'path' => $route, 'route' => $route, 'status' => $status,
            'ms' => 5.0, 'page' => '/dashboard', 'pageOrigin' => 'http://localhost', 'bytes' => 10, 'items' => null,
            'queries' => [], 'exceptions' => [],
        ];
    }

    /**
     * @return list<string>
     */
    private static function routes(RequestStore $store): array
    {
        $routes = array_map(fn (array $r) => $r['route'].'#'.$r['status'], $store->list(0, 1000));
        sort($routes);

        return $routes;
    }

    public function test_a_page_polling_one_route_cannot_push_every_other_request_out(): void
    {
        $store = new RequestStore($this->dir.'/requests.sqlite');

        $store->add(self::request('/api/users'), 300, 10);
        $store->add(self::request('/api/sales'), 300, 10);
        $store->add(self::request('/api/poll', 500), 300, 10);

        for ($i = 0; $i < 117; $i++) {
            $store->add(self::request('/api/poll'), 300, 10);
        }

        $routes = self::routes($store);

        $this->assertContains('/api/users#200', $routes);
        $this->assertContains('/api/sales#200', $routes);
        $this->assertContains('/api/poll#500', $routes, 'a failure is kept among the polling');
        $this->assertSame(10, count(array_keys($routes, '/api/poll#200', true)), 'only the newest ten successful polls');
    }

    public function test_the_newest_requests_are_the_ones_kept(): void
    {
        $store = new RequestStore($this->dir.'/requests.sqlite');

        for ($i = 0; $i < 40; $i++) {
            $store->add(self::request('/api/r'.$i), 20, 30);
        }

        $kept = array_map(fn (array $r) => $r['id'], $store->list(0, 1000));

        $this->assertSame(range(40, 21), $kept, 'newest first, nothing older than the newest 20');
    }
}
