<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Unit;

use BlastRadius\Console\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class GraphBuildCommandTest extends TestCase
{
    public function test_it_builds_then_reuses_the_cache(): void
    {
        $db = sys_get_temp_dir().'/blast-'.uniqid().'/graph.sqlite';
        $options = ['--path' => __DIR__.'/../Fixtures', '--paths' => 'Calls', '--db' => $db];

        try {
            $first = new CommandTester((new Application)->find('graph:build'));
            $first->execute($options);

            $this->assertSame(0, $first->getStatusCode());
            $this->assertStringContainsString('1 files: 1 analysed, 0 unchanged', $first->getDisplay());
            $this->assertMatchesRegularExpression('/\d+ CONFIRMED, \d+ POSSIBLE, 2 UNRESOLVED/', $first->getDisplay());

            $second = new CommandTester((new Application)->find('graph:build'));
            $second->execute($options);
            $this->assertStringContainsString('1 files: 0 analysed, 1 unchanged', $second->getDisplay());

            $fresh = new CommandTester((new Application)->find('graph:build'));
            $fresh->execute($options + ['--fresh' => true]);
            $this->assertStringContainsString('1 files: 1 analysed, 0 unchanged', $fresh->getDisplay());
        } finally {
            @unlink($db);
            @rmdir(dirname($db));
        }
    }
}
