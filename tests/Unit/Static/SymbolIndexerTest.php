<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Unit\Static;

use BlastRadius\Core\Config;
use BlastRadius\Core\NodeKind;
use BlastRadius\Static\SymbolIndexer;
use PHPUnit\Framework\TestCase;

final class SymbolIndexerTest extends TestCase
{
    public function test_it_collects_symbols_with_line_ranges(): void
    {
        $table = (new SymbolIndexer(new Config(paths: ['Symbols'])))->index(__DIR__.'/../../Fixtures');

        $expected = [
            'App\Services\Stockable' => [NodeKind::Interface, 5, 8],
            'App\Services\Stockable::stock' => [NodeKind::Method, 7, 7],
            'App\Services\Loggable' => [NodeKind::Trait, 10, 15],
            'App\Services\Loggable::log' => [NodeKind::Method, 12, 14],
            'App\Services\Status' => [NodeKind::Enum, 17, 25],
            'App\Services\Status::label' => [NodeKind::Method, 21, 24],
            'App\Services\PurchaseService' => [NodeKind::Class_, 27, 45],
            'App\Services\PurchaseService::stock' => [NodeKind::Method, 31, 34],
            'App\Services\PurchaseService::updateStock' => [NodeKind::Method, 36, 44],
            'App\Services\helper' => [NodeKind::Function, 47, 49],
        ];

        $this->assertCount(count($expected), $table->nodes, 'anonymous class members must not be indexed');

        foreach ($expected as $name => [$kind, $start, $end]) {
            $node = $table->find($name);

            $this->assertNotNull($node, "missing symbol {$name}");
            $this->assertSame($kind, $node->kind, $name);
            $this->assertSame([$start, $end], [$node->startLine, $node->endLine], $name);
            $this->assertStringEndsWith('Symbols/PurchaseService.php', $node->file);
        }

        $this->assertSame([], $table->errors);
    }

    public function test_it_reports_files_that_fail_to_parse_and_keeps_going(): void
    {
        $dir = sys_get_temp_dir().'/blast-'.uniqid();
        mkdir($dir.'/app', 0777, true);
        file_put_contents($dir.'/app/Broken.php', '<?php class {');
        file_put_contents($dir.'/app/Good.php', '<?php namespace A; class Good {}');

        try {
            $table = (new SymbolIndexer(new Config(paths: ['app'])))->index($dir);
        } finally {
            unlink($dir.'/app/Broken.php');
            unlink($dir.'/app/Good.php');
            rmdir($dir.'/app');
            rmdir($dir);
        }

        $this->assertNotNull($table->find('A\Good'));
        $this->assertCount(1, $table->errors);
    }
}
