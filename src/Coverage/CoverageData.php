<?php

declare(strict_types=1);

namespace BlastRadius\Coverage;

use RuntimeException;

final class CoverageData
{
    /**
     * Reads a `--coverage-php` file. It is a PHP file that returns a php-code-coverage object,
     * so the host's vendor autoloader must be loaded for it to unserialize.
     *
     * @return array{lines: array<string, array<int, mixed>>, testIds: array<int, string>}
     */
    public static function read(string $file): array
    {
        if (! is_file($file)) {
            throw new RuntimeException('The test run did not write a coverage file. Check that a coverage driver (pcov or Xdebug) is enabled for the test runner and that the test run itself starts (try `vendor/bin/phpunit` on its own).');
        }

        $coverage = (static fn () => include $file)();

        if (! is_object($coverage) || ! method_exists($coverage, 'getData')) {
            throw new RuntimeException('The coverage file does not contain a php-code-coverage object.');
        }

        $data = $coverage->getData();

        if (! is_object($data) || ! method_exists($data, 'lineCoverage')) {
            throw new RuntimeException('Unsupported php-code-coverage version.');
        }

        // php-code-coverage 14.3+ keys each line's hits by a test index and lists the ids separately.
        $testIds = method_exists($data, 'testIds') ? $data->testIds() : [];

        return ['lines' => $data->lineCoverage(), 'testIds' => is_array($testIds) ? array_values(array_map('strval', $testIds)) : []];
    }
}
