<?php

namespace Fixture\App\Http\Controllers;

use Fixture\App\Facades\Purchases;

class PingController
{
    public function __invoke(): string
    {
        Purchases::recalc();

        return 'pong';
    }
}
