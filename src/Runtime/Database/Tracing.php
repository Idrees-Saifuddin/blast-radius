<?php

declare(strict_types=1);

namespace BlastRadius\Runtime\Database;

use Illuminate\Database\Connection;

final class Tracing
{
    /**
     * Makes every database connection created from now on report rows and failures. Laravel
     * lets an application swap the connection class per driver; connections that already
     * exist are left alone.
     */
    public static function register(): void
    {
        // driver => [our connection class, the framework class it extends]
        $drivers = [
            'mysql' => [TracedMySqlConnection::class, 'Illuminate\\Database\\MySqlConnection'],
            'mariadb' => [TracedMariaDbConnection::class, 'Illuminate\\Database\\MariaDbConnection'],
            'pgsql' => [TracedPostgresConnection::class, 'Illuminate\\Database\\PostgresConnection'],
            'sqlite' => [TracedSQLiteConnection::class, 'Illuminate\\Database\\SQLiteConnection'],
            'sqlsrv' => [TracedSqlServerConnection::class, 'Illuminate\\Database\\SqlServerConnection'],
        ];

        foreach ($drivers as $driver => [$class, $base]) {
            // MariaDbConnection only exists in Laravel 11+. Check the framework class first:
            // autoloading ours would be a fatal error when its parent is missing.
            if (class_exists($base)) {
                Connection::resolverFor($driver, static fn ($pdo, $database = '', $prefix = '', array $config = []) => new $class($pdo, $database, $prefix, $config));
            }
        }
    }
}
