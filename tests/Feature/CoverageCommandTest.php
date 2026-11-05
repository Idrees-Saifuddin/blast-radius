<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Feature;

use BlastRadius\Coverage\CoverageRunner;
use BlastRadius\Impact\TestSelector;
use BlastRadius\Laravel\BlastRadiusServiceProvider;
use BlastRadius\Static\GraphStore;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase;

final class CoverageCommandTest extends TestCase
{
    private string $root;

    private string $db;

    protected function getPackageProviders($app): array
    {
        return [BlastRadiusServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('blastradius.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! CoverageRunner::driverAvailable()) {
            $this->markTestSkipped('Needs pcov or Xdebug.');
        }

        $this->root = realpath(__DIR__.'/../Fixtures/CoverageApp') ?: throw new \LogicException('Fixture missing.');
        $this->db = sys_get_temp_dir().'/blast-cov-'.uniqid().'/graph.sqlite';
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            @unlink($this->db);
            @rmdir(dirname($this->db));
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $options
     * @return array{int, string} exit code and output
     */
    private function runCommand(array $options = []): array
    {
        $status = Artisan::call('blast:coverage', $options + [
            '--path' => $this->root,
            '--paths' => 'src',
            '--tests' => 'tests',
            '--db' => $this->db,
            '--phpunit' => dirname(__DIR__, 2).'/vendor/bin/phpunit',
        ]);

        return [$status, Artisan::output()];
    }

    public function test_changing_a_method_selects_exactly_the_tests_that_execute_it(): void
    {
        [$status, $output] = $this->runCommand();
        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('3 tests recorded; 4 of 5 methods', $output);

        $this->assertSame(
            ['CoverageApp\Tests\CalculatorTest::test_add', 'CoverageApp\Tests\ReportTest::test_render'],
            $this->selectedTests(['CoverageApp\Calculator::add']),
        );
        $this->assertSame(['CoverageApp\Tests\CalculatorTest::test_multiply'], $this->selectedTests(['CoverageApp\Calculator::multiply']));
        $this->assertSame(['CoverageApp\Tests\ReportTest::test_render'], $this->selectedTests(['CoverageApp\Report::render']));
        $this->assertSame([], $this->selectedTests(['CoverageApp\Calculator::unused']), 'a method no test runs selects nothing');

        $table = (new GraphStore($this->db))->table();
        $files = TestSelector::select($table->nodes, $table->edges, ['CoverageApp\Calculator::multiply']);
        $this->assertSame($this->root.'/tests/CalculatorTest.php', $files['CoverageApp\Tests\CalculatorTest::test_multiply']);
    }

    /**
     * @param  list<string>  $changed
     * @return list<string>
     */
    private function selectedTests(array $changed): array
    {
        $table = (new GraphStore($this->db))->table();

        return array_keys(TestSelector::select($table->nodes, $table->edges, $changed));
    }

    public function test_a_filter_keeps_the_run_small(): void
    {
        [$status, $output] = $this->runCommand(['--filter' => 'ReportTest']);
        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('1 tests recorded', $output);
    }

    public function test_it_refuses_to_run_without_a_verified_test_database(): void
    {
        $app = sys_get_temp_dir().'/blast-app-'.uniqid();
        mkdir($app);
        file_put_contents($app.'/artisan', '<?php');

        try {
            [$status, $output] = $this->runCommand(['--path' => $app]);
        } finally {
            unlink($app.'/artisan');
            rmdir($app);
        }

        $this->assertSame(1, $status);
        $this->assertStringContainsString('No test database is configured', $output);
        $this->assertStringContainsString('Nothing was run', $output);
    }
}
