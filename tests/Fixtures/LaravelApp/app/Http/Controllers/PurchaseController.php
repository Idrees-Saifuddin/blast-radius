<?php

namespace Fixture\App\Http\Controllers;

use Fixture\App\Services\PurchaseService;

class PurchaseController
{
    public function __construct(private PurchaseService $service)
    {
    }

    public function store(): void
    {
        $this->service->updateStock(1);
    }

    public function update(): void
    {
        $this->service->created();
    }
}
