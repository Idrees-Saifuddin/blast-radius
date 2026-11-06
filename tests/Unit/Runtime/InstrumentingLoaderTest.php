<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Unit\Runtime;

use BlastRadius\Runtime\InstrumentingLoader;
use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class InstrumentingLoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/blast-loader-'.uniqid();
        mkdir($this->dir.'/app', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ([...(glob($this->dir.'/cache/*') ?: []), ...(glob($this->dir.'/app/*') ?: [])] as $file) {
            unlink($file);
        }

        @rmdir($this->dir.'/cache');
        rmdir($this->dir.'/app');
        rmdir($this->dir);
    }

    private function psr4(string $prefix, string $path): void
    {
        foreach (spl_autoload_functions() as $loader) {
            if (is_array($loader) && $loader[0] instanceof ClassLoader) {
                $loader[0]->addPsr4($prefix, $path);

                return;
            }
        }

        $this->fail('no Composer class loader to extend');
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    public function test_it_reports_what_was_timed_and_what_ran_as_it_is(): void
    {
        file_put_contents($this->dir.'/app/Worker.php', '<?php namespace Cov; class Worker { public function run(): int { return 1; } }');
        file_put_contents($this->dir.'/app/Marker.php', '<?php namespace Cov; interface Marker {}');
        file_put_contents($this->dir.'/app/Early.php', '<?php namespace Cov; class Early { public function boot(): void {} }');
        $this->psr4('Cov\\', $this->dir.'/app');

        // a service provider is loaded before timing starts: it never goes through the loader
        require $this->dir.'/app/Early.php';
        InstrumentingLoader::register($this->dir, ['app'], $this->dir.'/cache');

        $this->assertTrue(class_exists('Cov\Worker'));
        $this->assertTrue(interface_exists('Cov\Marker'));

        $coverage = InstrumentingLoader::coverage();
        $this->assertNotNull($coverage);
        $this->assertSame(1, $coverage['timed'], 'Worker ran as a timed copy');
        $this->assertSame(
            [['file' => (string) realpath($this->dir).'/app/Early.php', 'reason' => 'early']],
            $coverage['untimed'],
            'Early ran before timing started; Marker has nothing to time and is not reported',
        );

        $this->assertCount(1, glob($this->dir.'/cache/*.skip') ?: [], 'the interface is remembered, so it is not parsed again');
    }

    /**
     * @runInSeparateProcess
     *
     * @preserveGlobalState disabled
     */
    #[RunInSeparateProcess]
    public function test_it_reports_nothing_when_timing_was_never_set_up(): void
    {
        $this->assertNull(InstrumentingLoader::coverage());
    }
}
