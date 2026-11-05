<?php

namespace App\Orders;

class Base
{
    public function run(): void
    {
        $this->step();
    }

    public function step(): void
    {
    }

    public static function make(): static
    {
        return new static;
    }
}

class Child extends Base
{
    public function step(): void
    {
        parent::step();
    }
}

class Order
{
    public function __construct()
    {
    }

    public static function create(): self
    {
        return new self;
    }
}

class Service extends Base
{
    public function handle(string $name, string $class): void
    {
        $order = new Order;
        Order::create();
        self::helper();
        static::helper();
        $this->step();
        $this->$name();
        $made = new $class;
    }

    private static function helper(): void
    {
    }
}
