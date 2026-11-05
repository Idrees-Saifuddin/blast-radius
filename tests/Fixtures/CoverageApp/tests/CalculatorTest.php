<?php

namespace CoverageApp\Tests;

use CoverageApp\Calculator;
use PHPUnit\Framework\TestCase;

class CalculatorTest extends TestCase
{
    public function test_add(): void
    {
        $this->assertSame(3, (new Calculator)->add(1, 2));
    }

    public function test_multiply(): void
    {
        $this->assertSame(6, (new Calculator)->multiply(2, 3));
    }
}
