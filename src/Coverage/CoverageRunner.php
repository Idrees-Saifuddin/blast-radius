<?php

declare(strict_types=1);

namespace BlastRadius\Coverage;

use RuntimeException;

/**
 * Runs the host's test suite once in a child process with a coverage driver and
 * `--coverage-php`, so BlastRadius itself never has to load the host's test framework.
 */
final class CoverageRunner
{
    public function __construct(
        private readonly string $phpunit,
        private readonly string $php = PHP_BINARY,
    ) {}

    public static function driverAvailable(): bool
    {
        return extension_loaded('pcov') || extension_loaded('xdebug');
    }

    /**
     * @param  list<string>  $arguments  extra test runner arguments, e.g. `--filter=PurchaseTest`
     * @return array{exitCode: int, output: string}
     */
    public function run(string $root, string $coverageFile, array $arguments = []): array
    {
        if (! is_file($this->phpunit)) {
            throw new RuntimeException("Test runner not found: {$this->phpunit}");
        }

        $log = tempnam(sys_get_temp_dir(), 'blast-run-');

        if ($log === false) {
            throw new RuntimeException('Could not create a temporary file.');
        }

        $command = [$this->php, '-d', 'pcov.enabled=1', '-d', 'pcov.directory='.$root, $this->phpunit, '--coverage-php='.$coverageFile, ...$arguments];

        $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
            $pipes,
            $root,
            ['XDEBUG_MODE' => 'coverage'] + getenv(),
        );

        if (! is_resource($process)) {
            @unlink($log);

            throw new RuntimeException('Could not start the test runner.');
        }

        $exitCode = proc_close($process);
        $output = (string) file_get_contents($log);
        unlink($log);

        return ['exitCode' => $exitCode, 'output' => $output];
    }
}
