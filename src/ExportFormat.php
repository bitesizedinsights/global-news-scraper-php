<?php

declare(strict_types=1);

namespace GlobalNewsScraper;

/**
 * File formats of `ScrapeResult::saveTo()`.
 */
enum ExportFormat: string
{
    /** `ScrapeResult::toJson()`: articles and errors, restorable with `ScrapeResult::fromArray()`. */
    case Json = 'json';

    /** One article per line, in the shape of `Article::toArray()`; no errors. */
    case Jsonl = 'jsonl';

    /** One article per row for spreadsheets; no errors. */
    case Csv = 'csv';
}
