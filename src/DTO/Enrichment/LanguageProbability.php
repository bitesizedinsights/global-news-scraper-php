<?php

declare(strict_types=1);

namespace GlobalNewsScraper\DTO\Enrichment;

/**
 * One candidate language of the language detection.
 */
final readonly class LanguageProbability
{
    public function __construct(
        public string $language,
        public float $probability,
    ) {}
}
