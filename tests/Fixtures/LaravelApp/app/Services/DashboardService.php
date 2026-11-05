<?php

namespace Fixture\App\Services;

use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function totals(): array
    {
        $rows = DB::select('select 1 as n union select 2 as n');

        return array_map(fn ($row) => (array) $row, $rows);
    }

    public function slow(): void
    {
        usleep(30000);
    }

    public function broken(): void
    {
        DB::select('select * from missing_table');
    }
}
