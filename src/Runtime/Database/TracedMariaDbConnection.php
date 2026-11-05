<?php

declare(strict_types=1);

namespace BlastRadius\Runtime\Database;

use Illuminate\Database\MariaDbConnection as Base;

final class TracedMariaDbConnection extends Base
{
    use RecordsResults;
}
