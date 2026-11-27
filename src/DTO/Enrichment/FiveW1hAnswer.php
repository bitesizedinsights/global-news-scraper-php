<?php

declare(strict_types=1);

namespace GlobalNewsScraper\DTO\Enrichment;

use GlobalNewsScraper\Support\Lenient;

/**
 * One of the six 5W1H answers.
 */
final readonly class FiveW1hAnswer
{
    /**
     * @param  string|null  $answer  Null when the text does not answer the question, or the value is not a string.
     * @param  float|null  $confidence  0 to 1.
     */
    public function __construct(
        public ?string $answer = null,
        public ?float $confidence = null,
    ) {}

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            answer: Lenient::string($data['answer'] ?? null),
            confidence: Lenient::float($data['confidence'] ?? null),
        );
    }
}
