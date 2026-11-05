<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Feature;

use BlastRadius\Laravel\BlastRadiusServiceProvider;
use BlastRadius\Laravel\UiAccess;
use Orchestra\Testbench\TestCase;

final class UiRouteTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [BlastRadiusServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // the `web` middleware group encrypts cookies, which needs an app key (every real app has one)
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('blastradius.enabled', true);
        $app['config']->set('blastradius.ui.enabled', true);
        $app['config']->set('blastradius.root', realpath(__DIR__.'/../Fixtures/LaravelApp'));
        $app['config']->set('blastradius.paths', ['app', 'routes']);
        $app['config']->set('blastradius.cache', sys_get_temp_dir().'/blast-ui-'.uniqid().'/graph.sqlite');
    }

    protected function defineRoutes($router): void
    {
        require __DIR__.'/../Fixtures/LaravelApp/routes/web.php';
    }

    protected function tearDown(): void
    {
        UiAccess::reset();

        $cache = (string) config('blastradius.cache');
        @unlink($cache);
        @rmdir(dirname($cache));

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function reportData(string $html): array
    {
        preg_match('#<script id="report-data" type="application/json">(.*?)</script>#s', $html, $match);

        return json_decode($match[1] ?? throw new \LogicException('data script missing'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_by_default_this_machine_and_private_networks_may_open_it(): void
    {
        foreach (['127.0.0.1', '::1', '10.1.2.3', '172.18.0.5', '192.168.1.20'] as $address) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => $address])->get('/blast-radius');

            $this->assertSame(200, $response->getStatusCode(), $address);
        }

        $response = $this->get('/blast-radius');
        $response->assertOk();
        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_by_default_public_addresses_are_refused(): void
    {
        foreach (['8.8.8.8', '151.101.1.69', '2606:4700:4700::1111'] as $address) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => $address])->get('/blast-radius');

            $this->assertSame(403, $response->getStatusCode(), $address);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->getJson('/blast-radius/requests')->assertForbidden();
    }

    public function test_the_access_hook_can_open_it_to_other_addresses_and_the_page_shows_the_apps_flows(): void
    {
        UiAccess::using(fn () => true);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get('/blast-radius');
        $response->assertOk();

        $data = $this->reportData((string) $response->getContent());
        $entries = array_column($data['entries'], null, 'name');

        $this->assertTrue($data['meta']['live']);
        $this->assertArrayHasKey('route GET /ping', $entries);
        $this->assertArrayHasKey('route POST /api/purchases', $entries);
        $this->assertNull($data['impact']);
    }

    public function test_the_access_hook_can_also_close_it_to_this_machine(): void
    {
        UiAccess::using(fn () => false);

        $this->get('/blast-radius')->assertForbidden();
    }

    public function test_a_base_that_could_be_read_as_a_git_option_is_refused(): void
    {
        UiAccess::using(fn () => true);

        $this->get('/blast-radius?base=--output=/tmp/x')->assertStatus(422);
        $this->get('/blast-radius?base='.urlencode('main; rm -rf /'))->assertStatus(422);
    }

    public function test_an_unknown_base_is_a_readable_error_not_a_crash(): void
    {
        UiAccess::using(fn () => true);

        $response = $this->get('/blast-radius?base=no-such-branch-xyz');

        $response->assertStatus(500);
        $this->assertStringContainsString('could not build the report', (string) $response->getContent());
    }
}
