<?php

namespace Fixture\App\Http\Controllers;

use Fixture\App\Services\DashboardService;

class DashboardController
{
    public function __construct(private DashboardService $service)
    {
    }

    public function index(): array
    {
        $this->service->slow();
        $rows = $this->service->totals();
        $rows[] = ['n' => 3];

        return $rows;
    }

    public function broken(): void
    {
        $this->service->broken();
    }
}
