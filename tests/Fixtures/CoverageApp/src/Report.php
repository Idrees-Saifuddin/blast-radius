<?php

namespace CoverageApp;

class Report
{
    public function __construct(private Calculator $calculator)
    {
    }

    public function render(): string
    {
        return (string) $this->calculator->add(1, 2);
    }
}
