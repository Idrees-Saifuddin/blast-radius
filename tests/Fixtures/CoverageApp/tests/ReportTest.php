<?php

namespace CoverageApp\Tests;

use CoverageApp\Calculator;
use CoverageApp\Report;
use PHPUnit\Framework\TestCase;

class ReportTest extends TestCase
{
    public function test_render(): void
    {
        $this->assertSame('3', (new Report(new Calculator))->render());
    }
}
