<?php

declare(strict_types=1);

namespace GlobalNewsScraper\Support;

use GlobalNewsScraper\DTO\Article;
use GlobalNewsScraper\DTO\ScrapeResult;
use GlobalNewsScraper\Exception\InvalidInputException;
use GlobalNewsScraper\ExportFormat;
use JsonException;

/**
 * Renders a `ScrapeResult` in the export formats.
 *
 * @internal
 */
final class Exporter
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE;

    /** Keys of `Article::toArray()`, the CSV header also without any article. */
    private const COLUMNS = [
        'url', 'collection', 'article_title', 'authors', 'date_publish', 'article_description', 'text', 'language',
        'image_url', 'source_domain', 'type', 'sentiment', 'tags', 'summary', 'five_w1h', 'detected_language',
        'fixed_text', 'error',
    ];

    public static function format(string $path): ExportFormat
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'json' => ExportFormat::Json,
            'jsonl', 'ndjson' => ExportFormat::Jsonl,
            'csv' => ExportFormat::Csv,
            default => throw new InvalidInputException(
                'Cannot tell the format from the file extension (.json, .jsonl, .ndjson, .csv): pass an ExportFormat',
            ),
        };
    }

    /** @throws JsonException */
    public static function json(mixed $data, int $flags = 0): string
    {
        return json_encode($data, $flags | self::JSON_FLAGS);
    }

    /** @throws JsonException */
    public static function render(ScrapeResult $result, ExportFormat $format): string
    {
        return match ($format) {
            ExportFormat::Json => self::json($result->toArray())."\n",
            ExportFormat::Jsonl => self::jsonl($result),
            ExportFormat::Csv => self::csv($result),
        };
    }

    /** @throws JsonException */
    private static function jsonl(ScrapeResult $result): string
    {
        $lines = '';

        foreach ($result->entries as $article) {
            $lines .= self::json($article->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        }

        return $lines;
    }

    /** @throws JsonException */
    private static function csv(ScrapeResult $result): string
    {
        $csv = self::row(self::COLUMNS);

        foreach ($result->entries as $article) {
            $csv .= self::row(self::cells($article));
        }

        return $csv;
    }

    /**
     * @return list<string>
     *
     * @throws JsonException
     */
    private static function cells(Article $article): array
    {
        $data = $article->toArray();
        $cells = [];

        foreach (self::COLUMNS as $column) {
            $value = $data[$column];
            $cell = match (true) {
                $value === null => '',
                is_string($value) => self::clean($value),
                default => self::json($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            };

            $cells[] = self::neutralize($cell);
        }

        return $cells;
    }

    /** Valid UTF-8 (invalid bytes become U+FFFD) without NUL bytes. */
    private static function clean(string $value): string
    {
        /** @var string $valid */
        $valid = json_decode(self::json($value), false, 512, JSON_THROW_ON_ERROR);

        return str_replace("\0", '', $valid);
    }

    /**
     * Spreadsheets run a cell that starts like a formula. Such a cell gets a leading apostrophe, also when white
     * space, control characters or a byte order mark come first.
     */
    private static function neutralize(string $cell): string
    {
        $formula = preg_match('/\A(?:[\t\r\n]|[\s\p{Z}\p{C}]*[=+\-@＝＋－＠])/u', $cell) === 1;

        return $formula ? "'".$cell : $cell;
    }

    /**
     * Every field is quoted (RFC 4180), so that a spreadsheet with another delimiter cannot split a cell and start a
     * new one with a formula.
     *
     * @param  list<string>  $cells
     */
    private static function row(array $cells): string
    {
        return implode(',', array_map(
            static fn (string $cell): string => '"'.str_replace('"', '""', $cell).'"',
            $cells,
        ))."\r\n";
    }
}
