<?php

declare(strict_types=1);

namespace GlobalNewsScraper\DTO;

use GlobalNewsScraper\PricingTier;

/**
 * Estimated charges of a run plan in US dollars, see `CostEstimator`. Not a quote: only Apify's bill counts.
 * The `max...` values are upper bounds and null when the article number is unlimited.
 */
final readonly class CostEstimate
{
    /**
     * @param  int  $runs  Number of collector runs.
     * @param  float  $startFeesUsd  Start fees of all runs.
     * @param  int|null  $maxArticles  Most articles all runs together can return.
     * @param  float|null  $maxArticlesUsd  Article price of `$maxArticles`.
     * @param  float|null  $maxAddOnsUsd  Requested add-ons on all of `$maxArticles`; 0 without add-ons.
     * @param  float|null  $maxTotalUsd  Start fees, articles and add-ons.
     */
    public function __construct(
        public PricingTier $tier,
        public int $runs,
        public float $startFeesUsd,
        public ?int $maxArticles,
        public ?float $maxArticlesUsd,
        public ?float $maxAddOnsUsd,
        public ?float $maxTotalUsd,
    ) {}

    public function isBounded(): bool
    {
        return $this->maxTotalUsd !== null;
    }
}
