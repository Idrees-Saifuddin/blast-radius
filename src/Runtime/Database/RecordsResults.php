<?php

declare(strict_types=1);

namespace BlastRadius\Runtime\Database;

use BlastRadius\Runtime\RequestRecorder;
use Throwable;

/**
 * Wraps the database connection methods that run statements so the recorder learns what
 * Laravel's own query event leaves out: how many rows came back or were changed, and which
 * statements failed. Behaviour is unchanged: results and exceptions pass straight through.
 */
trait RecordsResults
{
    /**
     * @param  array<int|string, mixed>  $bindings
     * @return array<int, mixed>
     */
    public function select($query, $bindings = [], $useReadPdo = true)
    {
        $started = hrtime(true);

        try {
            $rows = parent::select($query, $bindings, $useReadPdo);
        } catch (Throwable $e) {
            RequestRecorder::queryFailed((string) $query, count((array) $bindings), (int) $started, $e, (string) $this->getName());

            throw $e;
        }

        RequestRecorder::rowsReturned(is_countable($rows) ? count($rows) : null, 'returned');

        return $rows;
    }

    /**
     * @param  array<int|string, mixed>  $bindings
     */
    public function affectingStatement($query, $bindings = [])
    {
        $started = hrtime(true);

        try {
            $affected = parent::affectingStatement($query, $bindings);
        } catch (Throwable $e) {
            RequestRecorder::queryFailed((string) $query, count((array) $bindings), (int) $started, $e, (string) $this->getName());

            throw $e;
        }

        RequestRecorder::rowsReturned(is_int($affected) ? $affected : null, 'affected');

        return $affected;
    }

    /**
     * @param  array<int|string, mixed>  $bindings
     */
    public function statement($query, $bindings = [])
    {
        $started = hrtime(true);

        try {
            return parent::statement($query, $bindings);
        } catch (Throwable $e) {
            RequestRecorder::queryFailed((string) $query, count((array) $bindings), (int) $started, $e, (string) $this->getName());

            throw $e;
        }
    }
}
