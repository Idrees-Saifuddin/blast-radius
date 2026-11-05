<?php

namespace App\Services;

interface Stockable
{
    public function stock(): int;
}

trait Loggable
{
    public function log(string $m): void
    {
    }
}

enum Status: string
{
    case Active = 'a';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}

class PurchaseService implements Stockable
{
    use Loggable;

    public function stock(): int
    {
        return 0;
    }

    public function updateStock(): void
    {
        $x = new class
        {
            public function inner(): void
            {
            }
        };
    }
}

function helper(): void
{
}
