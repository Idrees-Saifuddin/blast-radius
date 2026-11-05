<?php

namespace Fixture\App\Listeners;

use Fixture\App\Events\PurchaseCreated;
use Fixture\App\Services\PurchaseService;

class SendReceipt
{
    public function __construct(private PurchaseService $service)
    {
    }

    public function handle(PurchaseCreated $event): void
    {
        $this->service->updateStock(0);
    }
}
