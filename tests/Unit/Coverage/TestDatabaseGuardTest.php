<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Unit\Coverage;

use BlastRadius\Coverage\TestDatabaseGuard;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class TestDatabaseGuardTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/blast-guard-'.uniqid();
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            unlink($item->getPathname());
        }

        rmdir($this->root);
    }

    private function laravelApp(?string $phpunitDatabase = null, ?string $dotenvTesting = null): void
    {
        file_put_contents($this->root.'/artisan', '<?php');

        if ($phpunitDatabase !== null) {
            file_put_contents($this->root.'/phpunit.xml', '<phpunit><php><env name="DB_DATABASE" value="'.$phpunitDatabase.'"/></php></phpunit>');
        }

        if ($dotenvTesting !== null) {
            file_put_contents($this->root.'/.env.testing', "APP_ENV=testing\nDB_DATABASE={$dotenvTesting}\n");
        }
    }

    public function test_a_project_without_artisan_has_nothing_to_protect(): void
    {
        $this->assertTrue((new TestDatabaseGuard)->check($this->root)->safe);
    }

    public function test_it_refuses_when_no_test_database_is_configured(): void
    {
        $this->laravelApp();

        $result = (new TestDatabaseGuard)->check($this->root);

        $this->assertFalse($result->safe);
        $this->assertStringContainsString('DB_DATABASE is not set', $result->reason);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function databaseProvider(): array
    {
        return ['memory' => [':memory:'], 'suffix' => ['shop_test'], 'any case' => ['TESTING']];
    }

    /**
     * @dataProvider databaseProvider
     */
    #[DataProvider('databaseProvider')]
    public function test_it_accepts_memory_and_test_named_databases_from_phpunit_xml(string $database): void
    {
        $this->laravelApp($database);

        $this->assertTrue((new TestDatabaseGuard)->check($this->root)->safe);
    }

    public function test_it_refuses_a_database_that_does_not_look_like_a_test_one(): void
    {
        $this->laravelApp('shop_production');

        $result = (new TestDatabaseGuard)->check($this->root);

        $this->assertFalse($result->safe);
        $this->assertStringContainsString('shop_production', $result->reason);
    }

    public function test_it_falls_back_to_env_testing(): void
    {
        $this->laravelApp(null, 'shop_test');
        $this->assertTrue((new TestDatabaseGuard)->check($this->root)->safe);
    }

    public function test_phpunit_xml_wins_over_env_testing(): void
    {
        $this->laravelApp('shop_live', 'shop_test');
        $this->assertFalse((new TestDatabaseGuard)->check($this->root)->safe);
    }
}
