<?php

declare(strict_types=1);

namespace BlastRadius\Runtime\Database;

use Illuminate\Database\MySqlConnection as Base;

final class TracedMySqlConnection extends Base
{
    use RecordsResults;
}
