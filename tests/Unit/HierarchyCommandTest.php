<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Unit;

use BlastRadius\Console\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class HierarchyCommandTest extends TestCase
{
    /**
     * @param  array<string, string>  $input
     */
    private function run_command(array $input): CommandTester
    {
        $tester = new CommandTester((new Application)->find('blast:hierarchy'));
        $tester->execute($input + ['--path' => __DIR__.'/../Fixtures', '--paths' => 'Hierarchy']);

        return $tester;
    }

    public function test_it_shows_ancestors_and_descendants_of_a_class(): void
    {
        $tester = $this->run_command(['class' => 'App\Shapes\Circle']);
        $display = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('App\Shapes\Shape  [extends]', $display);
        $this->assertStringContainsString('App\Shapes\Resizable  [implements]', $display);
        $this->assertStringContainsString('App\Shapes\Ring  [extends]', $display);
    }

    public function test_it_lists_every_tree_without_an_argument(): void
    {
        $display = $this->run_command([])->getDisplay();

        $this->assertStringContainsString('App\Shapes\Drawable', $display);
        $this->assertStringContainsString('App\Shapes\HasName', $display);
    }

    public function test_it_fails_for_an_unknown_type(): void
    {
        $tester = $this->run_command(['class' => 'Nope\Missing']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Unknown type', $tester->getDisplay());
    }
}
