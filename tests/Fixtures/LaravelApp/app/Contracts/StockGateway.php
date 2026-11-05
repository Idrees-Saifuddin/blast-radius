<?php

namespace Fixture\App\Contracts;

interface StockGateway
{
    public function adjust(int $qty): void;
}
