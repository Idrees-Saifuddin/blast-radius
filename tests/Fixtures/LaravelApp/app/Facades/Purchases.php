<?php

namespace Fixture\App\Facades;

use Illuminate\Support\Facades\Facade;

class Purchases extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'purchases';
    }
}
