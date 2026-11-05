<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Feature;

use BlastRadius\Core\Confidence;
use BlastRadius\Core\Config;
use BlastRadius\Core\Edge;
use BlastRadius\Core\NodeKind;
use BlastRadius\Impact\Traversal;
use BlastRadius\Laravel\AppGraph;
use BlastRadius\Laravel\AppIntrospector;
use BlastRadius\Laravel\BindingUpgrader;
use BlastRadius\Laravel\BlastRadiusServiceProvider;
use BlastRadius\Static\Hierarchy;
use BlastRadius\Static\SymbolIndexer;
use BlastRadius\Static\SymbolTable;
use Fixture\App\Console\Commands\SyncStock;
use Fixture\App\Contracts\StockGateway;
use Fixture\App\Events\PurchaseCreated;
use Fixture\App\Listeners\SendReceipt;
use Fixture\App\Services\DbStockGateway;
use Fixture\App\Services\PurchaseService;
use Fixture\App\Tasks\NightlyRecalc;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Console\Kernel as FoundationKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;

final class LaravelAdapterTest extends TestCase
{
    private const SERVICE = 'Fixture\App\Services\PurchaseService';

    private string $root;

    private SymbolTable $table;

    private Hierarchy $hierarchy;

    private AppGraph $graph;

    /** @var list<Edge> */
    private array $edges;

    protected function getPackageProviders($app): array
    {
        return [BlastRadiusServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('blastradius.enabled', true);
    }

    protected function defineRoutes($router): void
    {
        require __DIR__.'/../Fixtures/LaravelApp/routes/web.php';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $app = $this->app ?? throw new \LogicException('Testbench did not create the application.');
        $this->root = realpath(__DIR__.'/../Fixtures/LaravelApp') ?: throw new \LogicException('Fixture app is missing.');

        $app->bind(StockGateway::class, DbStockGateway::class);
        $app->bind('purchases', PurchaseService::class);
        Event::listen(PurchaseCreated::class, SendReceipt::class);

        $kernel = $app->make(Kernel::class);
        $this->assertInstanceOf(FoundationKernel::class, $kernel);
        $kernel->registerCommand($app->make(SyncStock::class));
        $kernel->bootstrap();
        $schedule = $app->make(Schedule::class);
        $schedule->command('stock:sync')->daily();
        $schedule->call(new NightlyRecalc)->hourly();

        $this->table = (new SymbolIndexer(new Config(paths: ['app', 'routes'])))->index($this->root);
        $this->hierarchy = new Hierarchy($this->table);
        $this->graph = (new AppIntrospector($app, $this->hierarchy, $this->root))->introspect();
        $this->edges = BindingUpgrader::apply([...$this->table->edges, ...$this->graph->edges], $this->graph->bindings, $this->graph->facades, $this->hierarchy);
    }

    /**
     * @return list<string> `from -> to [kind] CONFIDENCE`
     */
    private function described(): array
    {
        return array_values(array_unique(array_map(
            fn (Edge $e) => "{$e->from} -> {$e->to} [{$e->kind->value}] {$e->confidence->value}",
            $this->edges,
        )));
    }

    private function assertEdge(string $edge): void
    {
        $this->assertContains($edge, $this->described());
    }

    public function test_routes_become_entry_points_with_their_handlers(): void
    {
        $names = array_column(array_filter($this->graph->nodes, fn ($n) => $n->kind === NodeKind::Route), 'name');
        sort($names);

        $this->assertSame([
            'route GET /closure',
            'route GET /ping',
            'route POST /api/purchases',
            'route PUT /api/purchases/{id}',
        ], $names);

        $controllers = 'Fixture\App\Http\Controllers';
        $this->assertEdge("route POST /api/purchases -> {$controllers}\PurchaseController::store [routes-to] CONFIRMED");
        $this->assertEdge("route PUT /api/purchases/{id} -> {$controllers}\PurchaseController::update [routes-to] CONFIRMED");
        $this->assertEdge("route GET /ping -> {$controllers}\PingController::__invoke [routes-to] CONFIRMED");
    }

    public function test_closure_routes_are_mapped_by_file_and_line_and_keep_their_calls(): void
    {
        $this->assertEdge('route GET /closure -> closure@routes/web.php:12 [routes-to] CONFIRMED');
        $this->assertEdge('closure@routes/web.php:12 -> '.self::SERVICE.'::updateStock [call] CONFIRMED');

        $closure = array_values(array_filter($this->graph->nodes, fn ($n) => $n->kind === NodeKind::Closure))[0];
        $this->assertSame([12, 15], [$closure->startLine, $closure->endLine]);
    }

    public function test_container_bindings_upgrade_interface_calls_to_confirmed(): void
    {
        $this->assertSame(
            ['Fixture\App\Contracts\StockGateway' => 'Fixture\App\Services\DbStockGateway', 'purchases' => self::SERVICE],
            array_intersect_key($this->graph->bindings, array_flip(['Fixture\App\Contracts\StockGateway', 'purchases'])),
        );

        $call = self::SERVICE.'::updateStock -> Fixture\App\Services';
        $this->assertEdge("{$call}\DbStockGateway::adjust [call] CONFIRMED");
        $this->assertEdge("{$call}\NullStockGateway::adjust [call] POSSIBLE");
        $this->assertEdge(self::SERVICE.'::updateStock -> Fixture\App\Contracts\StockGateway::adjust [call] CONFIRMED');
    }

    public function test_facade_calls_resolve_to_the_bound_class(): void
    {
        $this->assertSame(self::SERVICE, $this->graph->facades['Fixture\App\Facades\Purchases']);
        $this->assertEdge('Fixture\App\Http\Controllers\PingController::__invoke -> '.self::SERVICE.'::recalc [static-call] CONFIRMED');

        $unrewritten = array_filter($this->edges, fn (Edge $e) => str_contains($e->to, 'Facades\Purchases::'));
        $this->assertSame([], array_values($unrewritten), 'the virtual facade edge must be replaced');
    }

    public function test_events_listeners_jobs_commands_and_schedule(): void
    {
        $this->assertEdge(self::SERVICE.'::created -> Fixture\App\Events\PurchaseCreated [dispatches] CONFIRMED');
        $this->assertEdge('Fixture\App\Events\PurchaseCreated -> Fixture\App\Listeners\SendReceipt::handle [listens] CONFIRMED');
        $this->assertEdge('listener Fixture\App\Listeners\SendReceipt::handle -> Fixture\App\Listeners\SendReceipt::handle [handles] CONFIRMED');

        $this->assertEdge(self::SERVICE.'::recalc -> Fixture\App\Jobs\RecalculateStockJob [dispatches] CONFIRMED');
        $this->assertEdge('Fixture\App\Jobs\RecalculateStockJob -> Fixture\App\Jobs\RecalculateStockJob::handle [handles] CONFIRMED');
        $this->assertEdge('job Fixture\App\Jobs\RecalculateStockJob -> Fixture\App\Jobs\RecalculateStockJob::handle [handles] CONFIRMED');

        $this->assertEdge('command stock:sync -> Fixture\App\Console\Commands\SyncStock::handle [handles] CONFIRMED');
        $this->assertEdge('schedule 0 0 * * * Fixture\App\Console\Commands\SyncStock::handle -> Fixture\App\Console\Commands\SyncStock::handle [handles] CONFIRMED');
        $this->assertEdge('schedule 0 * * * * Fixture\App\Tasks\NightlyRecalc::__invoke -> Fixture\App\Tasks\NightlyRecalc::__invoke [handles] CONFIRMED');
    }

    public function test_it_finds_no_framework_noise(): void
    {
        $entries = array_column(array_filter($this->graph->nodes, fn ($n) => $n->kind->isEntryPoint()), 'name');

        foreach ($entries as $name) {
            $this->assertStringNotContainsString('Illuminate', $name);
        }

        $this->assertSame([], $this->graph->warnings);
    }

    public function test_a_route_reaches_everything_downstream_with_the_weakest_confidence(): void
    {
        $reached = Traversal::forward($this->edges, 'route GET /ping', 8);

        $this->assertSame(Confidence::Confirmed, $reached[self::SERVICE.'::recalc']['confidence']);
        // recalc dispatches the job class, the class reaches handle(), which calls updateStock()
        $this->assertSame(Confidence::Confirmed, $reached['Fixture\App\Jobs\RecalculateStockJob::handle']['confidence']);
        $this->assertSame(Confidence::Confirmed, $reached['Fixture\App\Services\DbStockGateway::adjust']['confidence']);
        $this->assertSame(Confidence::Possible, $reached['Fixture\App\Services\NullStockGateway::adjust']['confidence']);
        // route -> PingController::__invoke (1) -> PurchaseService::recalc (2)
        $this->assertSame(2, $reached[self::SERVICE.'::recalc']['depth']);
    }

    public function test_an_event_dispatch_reaches_its_listener(): void
    {
        $reached = Traversal::forward($this->edges, 'route PUT /api/purchases/{id}', 8);

        $this->assertArrayHasKey('Fixture\App\Listeners\SendReceipt::handle', $reached);
        $this->assertSame(Confidence::Confirmed, $reached['Fixture\App\Listeners\SendReceipt::handle']['confidence']);
    }

    public function test_the_graph_command_lists_entry_points_and_what_they_reach(): void
    {
        $db = sys_get_temp_dir().'/blast-'.uniqid().'/graph.sqlite';

        try {
            $status = Artisan::call('blast:graph', ['--path' => $this->root, '--paths' => 'app,routes', '--db' => $db, '--depth' => 8]);
            $output = Artisan::output();
        } finally {
            @unlink($db);
            @rmdir(dirname($db));
        }

        $this->assertSame(0, $status);
        $this->assertStringContainsString('route GET /ping', $output);
        $this->assertStringContainsString('command stock:sync', $output);
        $this->assertMatchesRegularExpression('/CONFIRMED\s+depth 2\s+'.preg_quote(self::SERVICE, '/').'::recalc/', $output);
        $this->assertMatchesRegularExpression('/POSSIBLE\s+depth \d\s+'.preg_quote('Fixture\App\Services\NullStockGateway', '/').'::adjust/', $output);
    }

    public function test_the_report_command_writes_a_self_contained_html_file(): void
    {
        $dir = sys_get_temp_dir().'/blast-report-'.uniqid();
        $out = $dir.'/report.html';

        try {
            $status = Artisan::call('blast:report', ['--path' => $this->root, '--paths' => 'app,routes', '--db' => $dir.'/graph.sqlite', '--out' => $out, '--depth' => 8]);
            $output = Artisan::output();
            $html = (string) file_get_contents($out);
        } finally {
            @unlink($out);
            @unlink($dir.'/graph.sqlite');
            @rmdir($dir);
        }

        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('Report written to', $output);

        preg_match('#<script id="report-data" type="application/json">(.*?)</script>#s', $html, $match);
        $data = json_decode($match[1] ?? throw new \LogicException('data script missing'), true, flags: JSON_THROW_ON_ERROR);

        $entries = array_column($data['entries'], null, 'name');
        $this->assertArrayHasKey('route GET /ping', $entries);
        $this->assertArrayHasKey('command stock:sync', $entries);

        $reached = array_column($entries['route GET /ping']['flow']['nodes'], 'c', 'n');
        $this->assertSame('CONFIRMED', $reached['Fixture\App\Jobs\RecalculateStockJob::handle']);
        $this->assertSame('app/Services/PurchaseService.php', $data['nodes'][self::SERVICE.'::recalc']['file']);
        $this->assertNull($data['impact']);
    }
}
