<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Feature;

use BlastRadius\Laravel\BlastRadiusServiceProvider;
use FilesystemIterator;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The whole product in one go: a real git repository, a real branch with a change, and the
 * `blast` command reporting what that change can reach.
 */
class BlastEndToEndTest extends TestCase
{
    protected string $repo;

    /** The Laravel app's root: the repository itself, or a subfolder of it. */
    protected string $appRoot;

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
        $router->post('/orders', 'Tmp\Http\Controllers\OrderController@store');
        $router->get('/health', fn () => 'ok');
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (shell_exec('git --version') === null) {
            $this->markTestSkipped('git is not installed.');
        }

        $this->repo = sys_get_temp_dir().'/blast-e2e-'.uniqid();
        $this->appRoot = $this->subdirectory() === '' ? $this->repo : $this->repo.'/'.$this->subdirectory();
        mkdir($this->appRoot.'/app/Http/Controllers', 0777, true);
        mkdir($this->appRoot.'/app/Services', 0777, true);

        file_put_contents($this->appRoot.'/app/Http/Controllers/OrderController.php', <<<'PHP'
<?php

namespace Tmp\Http\Controllers;

use Tmp\Services\OrderService;

class OrderController
{
    public function __construct(private OrderService $service)
    {
    }

    public function store(): int
    {
        return $this->service->place();
    }
}
PHP);
        $this->writeService('return 1;', 'place()');

        $this->git('init -q -b main');
        $this->git('add -A');
        $this->git('commit -q -m base');
        $this->git('checkout -q -b feature');
    }

    /**
     * Folder of the repository that holds the app; empty when the app is the repository root.
     */
    protected function subdirectory(): string
    {
        return '';
    }

    protected function tearDown(): void
    {
        if (isset($this->repo) && is_dir($this->repo)) {
            $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->repo, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }

            rmdir($this->repo);
        }

        parent::tearDown();
    }

    private function git(string $command): void
    {
        shell_exec('git -C '.escapeshellarg($this->repo).' -c user.name=t -c user.email=t@t.test '.$command.' 2>&1');
    }

    private function writeService(string $body, string $signature): void
    {
        file_put_contents($this->appRoot.'/app/Services/OrderService.php', <<<PHP
<?php

namespace Tmp\Services;

class OrderService
{
    public function {$signature}: int
    {
        {$body}
    }

    public function other(): int
    {
        return 2;
    }
}
PHP);
    }

    /**
     * @param  array<string, string>  $options
     * @return array{int, string}
     */
    private function blast(array $options = []): array
    {
        $status = Artisan::call('blast', $options + [
            '--base' => 'main',
            '--path' => $this->appRoot,
            '--paths' => 'app',
            '--db' => $this->appRoot.'/.blastradius/graph.sqlite',
            '--format' => 'json',
        ]);

        return [$status, Artisan::output()];
    }

    public function test_a_body_change_reports_the_route_that_reaches_it(): void
    {
        $this->writeService('return 10;', 'place()');
        $this->git('commit -q -am change');

        [$status, $output] = $this->blast();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $status, $output);
        $this->assertSame('Tmp\Services\OrderService::place', $report['changes'][0]['symbol']);
        $this->assertSame('modified', $report['changes'][0]['kind']);
        $this->assertFalse($report['changes'][0]['signature']);

        $this->assertCount(1, $report['entries']);
        $this->assertSame('route POST /orders', $report['entries'][0]['entry']);
        $this->assertSame('CONFIRMED', $report['entries'][0]['confidence']);
        $this->assertSame(2, $report['entries'][0]['depth']);
        // from the entry point down to the change
        $this->assertSame(['route POST /orders', 'Tmp\Http\Controllers\OrderController::store', 'Tmp\Services\OrderService::place'], $report['entries'][0]['path']);
        $this->assertFalse($report['entries'][0]['tested'], 'no coverage map was recorded');
        $this->assertSame([], $report['tests']);
    }

    public function test_changing_a_method_nothing_calls_reaches_no_entry_point(): void
    {
        file_put_contents($this->appRoot.'/app/Services/OrderService.php', str_replace('return 2;', 'return 3;', (string) file_get_contents($this->appRoot.'/app/Services/OrderService.php')));
        $this->git('commit -q -am change');

        [, $output] = $this->blast();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Tmp\Services\OrderService::other', $report['changes'][0]['symbol']);
        $this->assertSame([], $report['entries']);
    }

    public function test_a_signature_change_is_flagged(): void
    {
        $this->writeService('return 1;', 'place(int $qty = 1)');
        $this->git('commit -q -am change');

        [, $output] = $this->blast();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($report['changes'][0]['signature']);
    }

    public function test_the_text_report_and_the_ci_gate(): void
    {
        $this->writeService('return 10;', 'place()');
        $this->git('commit -q -am change');

        [$status, $output] = $this->blast(['--format' => 'text']);
        $this->assertSame(0, $status);
        $this->assertStringContainsString('Changed: Tmp\Services\OrderService::place   (modified)', $output);
        $this->assertStringContainsString('route POST /orders  (depth 2)  [no test executes this path]', $output);

        [$status, $output] = $this->blast(['--fail-on' => 'confirmed-untested']);
        $this->assertSame(1, $status, 'a CONFIRMED entry point without a covering test must fail the gate');
        $this->assertStringContainsString('route POST /orders', $output);
    }

    public function test_a_branch_without_php_changes_reports_nothing(): void
    {
        file_put_contents($this->appRoot.'/README.md', 'hello');
        $this->git('add -A');
        $this->git('commit -q -m docs');

        [$status, $output] = $this->blast(['--format' => 'text']);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('No PHP symbols changed', $output);
        $this->assertStringContainsString('README.md', $output, 'the report must say which files the diff touched');
    }

    public function test_an_empty_diff_says_so(): void
    {
        [$status, $output] = $this->blast(['--format' => 'text']);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('The diff is empty', $output);
    }

    public function test_an_unknown_base_branch_is_a_clear_error(): void
    {
        [$status, $output] = $this->blast(['--base' => 'does-not-exist']);

        $this->assertSame(1, $status);
        $this->assertNotSame('', trim($output));
    }
}
