<?php

declare(strict_types=1);

namespace GlobalNewsScraper\DTO\Enrichment;

use GlobalNewsScraper\Support\Lenient;

/**
 * Result of `withSentiment`, read from `Article::$sentiment`.
 */
final readonly class Sentiment
{
    /**
     * @param  float|null  $confidence  0 to 1; for VADER the absolute value of the compound score.
     */
    public function __construct(
        public SentimentLabel $label,
        public ?float $confidence = null,
        public ?SentimentScores $scores = null,
        public ?string $method = null,
    ) {}

    /**
     * Null when `$data` has no known `sentiment` label.
     *
     * @param  array<mixed>  $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $label = SentimentLabel::tryFrom(Lenient::string($data['sentiment'] ?? null) ?? '');

        if ($label === null) {
            return null;
        }

        $scores = Lenient::array($data['scores'] ?? null);

        return new self(
            label: $label,
            confidence: Lenient::float($data['confidence'] ?? null),
            scores: $scores === null ? null : SentimentScores::fromArray($scores),
            method: Lenient::string($data['method'] ?? null),
        );
    }
}
