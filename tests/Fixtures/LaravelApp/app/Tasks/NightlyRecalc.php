<?php

namespace Fixture\App\Tasks;

use Fixture\App\Services\PurchaseService;

class NightlyRecalc
{
    public function __invoke(PurchaseService $service): void
    {
        $service->recalc();
    }
}
