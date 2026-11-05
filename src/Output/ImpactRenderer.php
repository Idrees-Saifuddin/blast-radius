<?php

declare(strict_types=1);

namespace BlastRadius\Output;

use BlastRadius\Core\Confidence;
use BlastRadius\Impact\ImpactReport;

final class ImpactRenderer
{
    public static function render(ImpactReport $report, string $format): string
    {
        return match ($format) {
            'json' => self::json($report),
            'markdown', 'md' => self::markdown($report),
            default => self::text($report),
        };
    }

    private static function text(ImpactReport $report): string
    {
        $lines = [];

        foreach ($report->changes as $change) {
            $lines[] = "Changed: {$change->symbol}   ({$change->kind}".($change->signature ? ', signature changed' : '').')';
        }

        if ($report->changes === []) {
            return self::nothingChanged($report);
        }

        foreach ([Confidence::Confirmed, Confidence::Possible] as $level) {
            $entries = array_filter($report->entries, fn (array $e) => $e['confidence'] === $level);

            if ($entries === []) {
                continue;
            }

            $lines[] = '';
            $lines[] = $level->value;

            foreach ($entries as $entry) {
                $lines[] = sprintf('  %s  (depth %d)%s', $entry['entry'], $entry['depth'], $entry['tested'] ? '' : '  [no test executes this path]');
            }
        }

        if ($report->entries === []) {
            $lines[] = '';
            $lines[] = 'No route, job, listener, command or schedule entry reaches this change.';
        }

        $lines[] = '';
        $lines[] = sprintf('TESTS TO RUN (coverage map): %d', count($report->tests));

        foreach ($report->tests as $test => $file) {
            $lines[] = '  '.($file !== '' ? $file : $test);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Says what the diff did contain, so "nothing changed" can be told apart from "the change
     * is in a file or place that has no method or class" (README, composer.json, a comment
     * outside any class).
     */
    private static function nothingChanged(ImpactReport $report): string
    {
        if ($report->files === []) {
            return "No PHP symbols changed. The diff is empty: check --base and that your change is committed (or use --working-tree).\n";
        }

        $shown = array_slice($report->files, 0, 10);
        $more = count($report->files) - count($shown);

        return sprintf(
            "No PHP symbols changed. The diff touched %d file%s, none with a changed method, function or class:\n  %s%s\n",
            count($report->files),
            count($report->files) === 1 ? '' : 's',
            implode("\n  ", $shown),
            $more > 0 ? "\n  ... and {$more} more" : '',
        );
    }

    private static function markdown(ImpactReport $report): string
    {
        $out = ["## BlastRadius\n"];

        foreach ($report->changes as $change) {
            $out[] = "- **{$change->kind}** `{$change->symbol}`".($change->signature ? ' (signature changed: callers may break)' : '');
        }

        $out[] = '';

        if ($report->entries !== []) {
            $out[] = '| Confidence | Entry point | Depth | Tested |';
            $out[] = '|---|---|---|---|';

            foreach ($report->entries as $e) {
                $out[] = sprintf('| %s | `%s` | %d | %s |', $e['confidence']->value, $e['entry'], $e['depth'], $e['tested'] ? 'yes' : '**no**');
            }
        } else {
            $out[] = '_No entry point reaches this change._';
        }

        $out[] = '';
        $out[] = sprintf('**Tests to run (%d):**', count($report->tests));

        foreach ($report->tests as $test => $file) {
            $out[] = '- `'.($file !== '' ? $file : $test).'`';
        }

        return implode("\n", $out)."\n";
    }

    private static function json(ImpactReport $report): string
    {
        return json_encode([
            'changes' => array_map(fn ($c) => ['symbol' => $c->symbol, 'kind' => $c->kind, 'file' => $c->file, 'signature' => $c->signature], $report->changes),
            'entries' => array_map(fn (array $e) => ['entry' => $e['entry'], 'kind' => $e['kind'], 'depth' => $e['depth'], 'confidence' => $e['confidence']->value, 'tested' => $e['tested'], 'path' => $e['path']], $report->entries),
            'tests' => $report->tests,
            'files' => $report->files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }
}
