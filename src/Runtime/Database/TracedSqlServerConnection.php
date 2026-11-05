<?php

declare(strict_types=1);

namespace BlastRadius\Runtime\Database;

use Illuminate\Database\SqlServerConnection as Base;

final class TracedSqlServerConnection extends Base
{
    use RecordsResults;
}
