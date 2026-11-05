<?php

namespace CoverageApp;

class Calculator
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    public function multiply(int $a, int $b): int
    {
        return $a * $b;
    }

    public function unused(): int
    {
        return 0;
    }
}
