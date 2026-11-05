<?php

declare(strict_types=1);

namespace BlastRadius\Runtime\Database;

use Illuminate\Database\SQLiteConnection as Base;

final class TracedSQLiteConnection extends Base
{
    use RecordsResults;
}
