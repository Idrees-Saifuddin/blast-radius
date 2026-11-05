<?php

namespace Fixture\App\Services;

use Fixture\App\Contracts\StockGateway;
use Fixture\App\Events\PurchaseCreated;
use Fixture\App\Jobs\RecalculateStockJob;

class PurchaseService
{
    public function __construct(private StockGateway $gateway)
    {
    }

    public function updateStock(int $qty): void
    {
        $this->gateway->adjust($qty);
    }

    public function recalc(): void
    {
        RecalculateStockJob::dispatch();
    }

    public function created(): void
    {
        event(new PurchaseCreated);
    }
}
