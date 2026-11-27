<?php

declare(strict_types=1);

namespace GlobalNewsScraper\DTO\Enrichment;

use GlobalNewsScraper\Support\Lenient;

/**
 * Result of `withSummary`, read from `Article::$summary`.
 */
final readonly class Summary
{
    /**
     * @param  int|null  $originalLength  Length of the summarized text in characters.
     * @param  int|null  $summaryLength  Length of the summary in characters.
     * @param  float|null  $compressionRatio  Summary length divided by the original length.
     */
    public function __construct(
        public string $text,
        public ?int $originalLength = null,
        public ?int $summaryLength = null,
        public ?float $compressionRatio = null,
        public ?string $model = null,
    ) {}

    /**
     * Null when `$data` has no `summary` string.
     *
     * @param  array<mixed>  $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $text = Lenient::string($data['summary'] ?? null);

        if ($text === null) {
            return null;
        }

        $stats = Lenient::array($data['stats'] ?? null) ?? [];

        return new self(
            text: $text,
            originalLength: Lenient::int($stats['original_length'] ?? null),
            summaryLength: Lenient::int($stats['summary_length'] ?? null),
            compressionRatio: Lenient::float($stats['compression_ratio'] ?? null),
            model: Lenient::string($data['model'] ?? null),
        );
    }
}
