<?php

namespace Fixture\App\Jobs;

use Fixture\App\Services\PurchaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class RecalculateStockJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(PurchaseService $service): void
    {
        $service->updateStock(1);
    }
}
