<?php

namespace App\Shapes;

interface Drawable
{
    public function draw(): string;
}

interface Resizable extends Drawable
{
    public function resize(): void;
}

trait HasName
{
    public function name(): string
    {
        return 'n';
    }

    public function describe(): string
    {
        return 'trait';
    }
}

trait Loud
{
    use HasName;
}

abstract class Shape implements Drawable
{
    use HasName;

    public function area(): int
    {
        return 0;
    }

    public function describe(): string
    {
        return 'shape';
    }
}

class Circle extends Shape implements Resizable
{
    public function draw(): string
    {
        return 'o';
    }

    public function resize(): void
    {
    }
}

final class Ring extends Circle
{
    use Loud;

    public function area(): int
    {
        return 1;
    }
}
