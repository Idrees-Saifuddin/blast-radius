<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Feature;

use BlastRadius\Laravel\BlastRadiusServiceProvider;
use BlastRadius\Laravel\UiAccess;
use BlastRadius\Runtime\RequestStore;
use Illuminate\Database\QueryException;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Real requests through a real Laravel app. Each test runs in its own process so the
 * fixture classes are loaded fresh, through the instrumenting loader, and nothing else has
 * touched the tracer.
 */
final class RuntimeTracingTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        // PHPUnit runs on the command line, but tracing is for web requests: say we are serving one.
        putenv('APP_RUNNING_IN_CONSOLE=false');
        $_ENV['APP_RUNNING_IN_CONSOLE'] = 'false';
        $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'false';

        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [BlastRadiusServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $this->dir = sys_get_temp_dir().'/blast-runtime-'.uniqid();
        $app->useStoragePath($this->dir.'/storage');

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('blastradius.enabled', true);
        $app['config']->set('blastradius.ui.enabled', true);
        $app['config']->set('blastradius.runtime.enabled', true);
        $app['config']->set('blastradius.root', realpath(__DIR__.'/../Fixtures/LaravelApp'));
        $app['config']->set('blastradius.paths', ['app', 'routes']);
        $app['config']->set('blastradius.runtime.store', $this->dir.'/requests.sqlite');
        $app['config']->set('blastradius.cache', $this->dir.'/graph.sqlite');
        $app['config']->set('database.default', 'testing');
    }

    protected function defineRoutes($router): void
    {
        require __DIR__.'/../Fixtures/LaravelApp/routes/runtime.php';
    }

    protected function tearDown(): void
    {
        UiAccess::reset();

        parent::tearDown();
    }

    private function store(): RequestStore
    {
        return new RequestStore($this->dir.'/requests.sqlite');
    }

    /**
     * @return array<string, mixed>
     */
    private function latest(): array
    {
        $summary = $this->store()->list()[0] ?? throw new \LogicException('no request was recorded');

        return $this->store()->find($summary['id']) ?? throw new \LogicException('request vanished');
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    public function test_a_successful_api_call_is_recorded_with_its_page_functions_query_and_data_counts(): void
    {
        $this->withHeaders(['Referer' => 'http://localhost:3000/dashboard'])->getJson('/api/dashboard')->assertOk()->assertJsonCount(3);

        $summaries = $this->store()->list();
        $this->assertCount(1, $summaries);
        $summary = $summaries[0];

        $this->assertSame('GET', $summary['method']);
        $this->assertSame('/api/dashboard', $summary['path']);
        $this->assertSame('/api/dashboard', $summary['route']);
        $this->assertSame('/dashboard', $summary['page'], 'the page that made the call, from the Referer header');
        $this->assertSame('http://localhost:3000', $summary['pageOrigin']);
        $this->assertSame(200, $summary['status']);
        $this->assertSame(3, $summary['items'], 'three records in the JSON list');
        $this->assertGreaterThan(0, $summary['bytes']);
        $this->assertSame(1, $summary['queries']);
        $this->assertSame(0, $summary['errors']);
        $this->assertGreaterThanOrEqual(25, $summary['ms'], 'the request includes a 30 ms sleep');

        $record = $this->latest();
        $this->assertStringContainsString('DashboardController@index', (string) $record['action']);

        $spans = $record['spans'];
        $byName = array_column($spans, null, 'n');
        $index = 'Fixture\App\Http\Controllers\DashboardController::index';
        $totals = 'Fixture\App\Services\DashboardService::totals';
        $slow = 'Fixture\App\Services\DashboardService::slow';

        $this->assertArrayHasKey($index, $byName);
        $this->assertSame($byName[$index]['i'], $byName[$totals]['p'], 'totals() was called by the controller');
        $this->assertSame($byName[$index]['i'], $byName[$slow]['p']);
        $this->assertGreaterThanOrEqual(25, $byName[$slow]['ms']);
        $this->assertGreaterThanOrEqual($byName[$slow]['ms'] + $byName[$totals]['ms'], $byName[$index]['ms'] + 0.5, 'a parent includes its children');
        $this->assertGreaterThan($byName[$slow]['s'], $byName[$totals]['s'], 'totals() started after slow() began');

        $query = $record['queries'][0];
        $this->assertSame('select', $query['kind']);
        $this->assertTrue($query['ok']);
        $this->assertSame(2, $query['rows'], 'the query returned two rows');
        $this->assertSame('returned', $query['rowsKind']);
        $this->assertGreaterThanOrEqual(0, $query['ms']);
        $this->assertSame($totals, $spans[$query['span']]['n'], 'the function that issued the query');
        $this->assertStringStartsWith('app/Services/DashboardService.php:', (string) $query['caller']);

        $this->assertContains('app/Http/Controllers/DashboardController.php', $record['files']);
        $this->assertContains('app/Services/DashboardService.php', $record['files']);
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    public function test_the_page_comes_from_the_referer_unless_the_browser_sent_only_the_site_address(): void
    {
        // page and API on different addresses: browsers send the bare address, so the page is unknown
        $this->getJson('/api/dashboard', ['Referer' => 'http://localhost:3000/']);
        // a full URL: the path is kept and the query string dropped
        $this->getJson('/api/dashboard', ['Referer' => 'http://localhost:3000/dashboard/products?page=2#top']);
        // the frontend can state the page itself
        $this->getJson('/api/dashboard', ['Referer' => 'http://localhost:3000/', 'X-BlastRadius-Page' => '/reports/sales?range=7']);
        // same address as the API: a bare "/" really is the home page
        $this->getJson('/api/dashboard', ['Referer' => 'http://localhost/']);
        // no Referer at all
        $this->getJson('/api/dashboard');

        $pages = array_map(fn (array $r) => [$r['page'], $r['pageOrigin']], array_reverse($this->store()->list()));

        $this->assertSame([
            [null, 'http://localhost:3000'],
            ['/dashboard/products', 'http://localhost:3000'],
            ['/reports/sales', 'http://localhost:3000'],
            ['/', 'http://localhost'],
            [null, null],
        ], $pages);
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    public function test_a_failing_query_names_the_query_the_function_and_the_error(): void
    {
        $this->withHeaders(['Referer' => 'http://localhost:3000/reports'])->getJson('/api/broken')->assertStatus(500);

        $summary = $this->store()->list()[0];
        $this->assertSame(500, $summary['status']);
        $this->assertSame('/reports', $summary['page']);
        $this->assertSame(1, $summary['failedQueries']);
        $this->assertGreaterThanOrEqual(1, $summary['errors']);

        $record = $this->latest();
        $query = $record['queries'][0];

        $this->assertFalse($query['ok']);
        $this->assertSame('select * from missing_table', $query['sql']);
        $this->assertStringContainsString('no such table', (string) $query['error']);
        $this->assertNull($query['rows']);

        $spans = $record['spans'];
        $this->assertSame('Fixture\App\Services\DashboardService::broken', $spans[$query['span']]['n'], 'the function that ran the failing query');

        $failed = array_values(array_filter($spans, fn (array $s) => $s['f'] !== null));
        $this->assertSame(
            ['Fixture\App\Http\Controllers\DashboardController::broken', 'Fixture\App\Services\DashboardService::broken'],
            array_column($failed, 'n'),
            'the exception is marked on every function it passed through, from the outside in',
        );
        $this->assertStringContainsString('no such table', $failed[1]['f']['message']);

        $this->assertNotEmpty($record['exceptions']);
        $this->assertSame(QueryException::class, $record['exceptions'][0]['class']);
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    public function test_the_ui_requests_and_preflight_requests_are_not_recorded(): void
    {
        UiAccess::using(fn () => true);

        $this->get('/blast-radius')->assertOk();
        $this->call('OPTIONS', '/api/dashboard');

        $this->assertSame([], $this->store()->list());
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    public function test_the_live_view_endpoints_list_requests_and_return_one_in_full_with_function_locations(): void
    {
        UiAccess::using(fn () => true);

        $this->withHeaders(['Referer' => 'http://localhost:3000/dashboard'])->getJson('/api/dashboard')->assertOk();
        $this->get('/blast-radius')->assertOk(); // builds the graph cache the locations come from

        $list = $this->getJson('/blast-radius/requests')->assertOk()->json('requests');
        $this->assertCount(1, $list);
        $this->assertSame('/dashboard', $list[0]['page']);

        $this->assertCount(0, $this->getJson('/blast-radius/requests?after='.$list[0]['id'])->json('requests'), 'nothing newer than the latest');

        $detail = $this->getJson('/blast-radius/requests/'.$list[0]['id'])->assertOk()->json();
        $this->assertNotEmpty($detail['spans']);
        $this->assertSame('app/Services/DashboardService.php', $detail['locations']['Fixture\App\Services\DashboardService::totals']['file']);
        $this->assertGreaterThan(0, $detail['locations']['Fixture\App\Services\DashboardService::totals']['line']);

        $this->getJson('/blast-radius/requests/999999')->assertNotFound();
    }
}
