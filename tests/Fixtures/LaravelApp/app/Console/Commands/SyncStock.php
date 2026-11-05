<?php

namespace Fixture\App\Console\Commands;

use Fixture\App\Services\PurchaseService;
use Illuminate\Console\Command;

class SyncStock extends Command
{
    protected $signature = 'stock:sync';

    public function handle(PurchaseService $service): void
    {
        $service->recalc();
    }
}
