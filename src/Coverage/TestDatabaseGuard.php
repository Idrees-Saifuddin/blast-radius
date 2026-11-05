<?php

declare(strict_types=1);

namespace BlastRadius\Coverage;

/**
 * Running a Laravel app's test suite can touch its database (RefreshDatabase and friends
 * wipe it). Before we do that, make sure the tests are configured for a throwaway database:
 * `:memory:` or a name containing "test", set in phpunit.xml or .env.testing.
 */
final class TestDatabaseGuard
{
    public function check(string $root): GuardResult
    {
        if (! is_file($root.'/artisan')) {
            return new GuardResult(true, 'Not a Laravel application, so there is no database to protect.');
        }

        $database = $this->phpunitEnv($root, 'DB_DATABASE') ?? $this->dotenv($root.'/.env.testing', 'DB_DATABASE');

        if ($database === null) {
            return new GuardResult(false, 'No test database is configured: DB_DATABASE is not set in phpunit.xml or .env.testing, so the tests would use the database from .env.');
        }

        if ($database === ':memory:' || stripos($database, 'test') !== false) {
            return new GuardResult(true, "Tests use the database \"{$database}\".");
        }

        return new GuardResult(false, "The tests use the database \"{$database}\", which does not look like a test database (expected :memory: or a name containing \"test\").");
    }

    private function phpunitEnv(string $root, string $name): ?string
    {
        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $file) {
            if (! is_file($root.'/'.$file)) {
                continue;
            }

            $xml = @simplexml_load_file($root.'/'.$file);

            if ($xml === false) {
                continue;
            }

            foreach ($xml->xpath('//php/env | //php/server') ?: [] as $node) {
                if ((string) $node['name'] === $name) {
                    return (string) $node['value'];
                }
            }
        }

        return null;
    }

    private function dotenv(string $file, string $name): ?string
    {
        if (! is_file($file)) {
            return null;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^\s*'.preg_quote($name, '/').'\s*=\s*(.*)$/', $line, $match) === 1) {
                return trim($match[1], " \t\"'");
            }
        }

        return null;
    }
}
