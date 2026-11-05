<?php

declare(strict_types=1);

namespace BlastRadius\Tests\Unit;

use BlastRadius\BlastRadius;
use BlastRadius\Console\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class VersionCommandTest extends TestCase
{
    public function test_it_prints_the_version(): void
    {
        $tester = new CommandTester((new Application)->find('blast:version'));

        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('BlastRadius '.BlastRadius::VERSION, $tester->getDisplay());
    }
}
