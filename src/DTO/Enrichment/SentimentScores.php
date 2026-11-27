<?php

declare(strict_types=1);

namespace GlobalNewsScraper\DTO\Enrichment;

use GlobalNewsScraper\Support\Lenient;

/**
 * VADER scores: `positive`, `neutral` and `negative` are shares from 0 to 1, `compound` goes from -1 to 1.
 * A score that is missing or not a number is null.
 */
final readonly class SentimentScores
{
    public function __construct(
        public ?float $positive = null,
        public ?float $neutral = null,
        public ?float $negative = null,
        public ?float $compound = null,
    ) {}

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            positive: Lenient::float($data['positive'] ?? null),
            neutral: Lenient::float($data['neutral'] ?? null),
            negative: Lenient::float($data['negative'] ?? null),
            compound: Lenient::float($data['compound'] ?? null),
        );
    }
}
