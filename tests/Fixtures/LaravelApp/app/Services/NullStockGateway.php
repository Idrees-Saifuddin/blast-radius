<?php

namespace Fixture\App\Services;

use Fixture\App\Contracts\StockGateway;

class NullStockGateway implements StockGateway
{
    public function adjust(int $qty): void
    {
    }
}
