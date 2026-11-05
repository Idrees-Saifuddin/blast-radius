<?php

declare(strict_types=1);

namespace BlastRadius\Runtime\Database;

use Illuminate\Database\PostgresConnection as Base;

final class TracedPostgresConnection extends Base
{
    use RecordsResults;
}
