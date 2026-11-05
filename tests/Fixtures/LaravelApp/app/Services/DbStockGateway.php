<?php

namespace Fixture\App\Services;

use Fixture\App\Contracts\StockGateway;

class DbStockGateway implements StockGateway
{
    public function adjust(int $qty): void
    {
    }
}
