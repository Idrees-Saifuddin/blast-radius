<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Unit\Static;

use BlastRadius\Core\Config;
use BlastRadius\Static\FileFinder;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class FileFinderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = realpath(sys_get_temp_dir()).'/blast-'.uniqid();

        foreach ([
            'app/A.php', 'app/Sub/B.php', 'app/Skip/C.php', 'app/vendor/V.php', 'app/notes.txt',
            'routes/web.php', 'database/D.php', 'vendor/pkg/X.php',
        ] as $file) {
            if (! is_dir(dirname($this->root.'/'.$file))) {
                mkdir(dirname($this->root.'/'.$file), 0777, true);
            }

            file_put_contents($this->root.'/'.$file, '<?php');
        }
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->root);
    }

    public function test_it_finds_php_files_in_configured_paths_and_respects_excludes(): void
    {
        $found = (new FileFinder(new Config(paths: ['app', 'routes', 'missing'], excludes: ['app/Skip'])))->find($this->root);

        $this->assertSame([
            $this->root.'/app/A.php',
            $this->root.'/app/Sub/B.php',
            $this->root.'/routes/web.php',
        ], $found);
    }

    public function test_it_supports_glob_excludes_and_single_file_paths(): void
    {
        $found = (new FileFinder(new Config(paths: ['app', 'routes/web.php'], excludes: ['app/S*'])))->find($this->root);

        $this->assertSame([$this->root.'/app/A.php', $this->root.'/routes/web.php'], $found);
    }

    public function test_it_returns_nothing_for_a_missing_base_path(): void
    {
        $this->assertSame([], (new FileFinder(new Config))->find($this->root.'/nope'));
    }
}
