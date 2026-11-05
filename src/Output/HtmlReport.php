<?php

declare(strict_types=1);

namespace BlastRadius\Output;

/**
 * Renders the report data into one self-contained HTML file: styles, script and data are
 * inlined, so it opens offline in any browser and nothing is fetched from the internet.
 */
final class HtmlReport
{
    private const PLACEHOLDER = '__BLASTRADIUS_DATA__';

    /**
     * @param  array<string, mixed>  $data
     */
    public static function render(array $data): string
    {
        $template = (string) file_get_contents(__DIR__.'/../../resources/report.html');

        // The HEX flags turn <, >, & and quotes into \uXXXX escapes, so data can never close the script tag.
        $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);

        $position = strpos($template, self::PLACEHOLDER);

        return $position === false ? $template : substr_replace($template, $json, $position, strlen(self::PLACEHOLDER));
    }
}
