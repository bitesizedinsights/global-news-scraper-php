<?php

declare(strict_types=1);

namespace GlobalNewsScraper;

use GlobalNewsScraper\DTO\CostEstimate;
use GlobalNewsScraper\DTO\RunPlan;
use GlobalNewsScraper\Exception\InvalidInputException;
use GlobalNewsScraper\Support\PriceList;
use Illuminate\Support\Collection;

/**
 * Estimates what a run plan can cost, from the Actor prices built into this library version. It sends no request, so
 * it does not know price changes, your account's tier, discounts or your remaining credit.
 */
final class CostEstimator
{
    private const DEFAULT_LIMIT = 100;

    private const MAX_LIMIT = 10000;

    /**
     * Upper bound of the charges when every run returns `$limit` articles and every requested add-on succeeds on
     * each. Real costs are usually lower: runs can return fewer articles, add-ons are only billed where they succeed
     * and text fixing only for repaired articles. `Client::scrape()` bills duplicates it removes, too; they are
     * within the limit of their run.
     *
     * @param  RunPlan|array<mixed>|Collection<array-key, mixed>  $plan  One `RunPlan` or the list of `Client::plan()`;
     *                                                                   every item counts as one run.
     * @param  int|null  $limit  As for `scrape()`: per run, null = Actor default 100, 0 = unlimited (no upper bound).
     *
     * @throws InvalidInputException for a plan item that is no `RunPlan` or a limit outside of 0 to 10000
     */
    public static function estimate(
        RunPlan|array|Collection $plan,
        ?int $limit = null,
        PricingTier $tier = PricingTier::Free,
        bool $withSentiment = false,
        bool $withTags = false,
        bool $withSummary = false,
        bool $with5w1h = false,
        bool $withLanguageDetection = false,
        bool $withFixText = false,
    ): CostEstimate {
        if ($limit !== null && ($limit < 0 || $limit > self::MAX_LIMIT)) {
            throw new InvalidInputException('limit must be between 0 and '.self::MAX_LIMIT);
        }

        $limit ??= self::DEFAULT_LIMIT;
        $items = match (true) {
            $plan instanceof RunPlan => [$plan],
            $plan instanceof Collection => $plan->all(),
            default => $plan,
        };

        $addOnPrice = 0;
        $addOns = [
            'with_sentiment' => $withSentiment,
            'with_tags' => $withTags,
            'with_summary' => $withSummary,
            'with_5w1h' => $with5w1h,
            'with_language_detection' => $withLanguageDetection,
            'with_fix_text' => $withFixText,
        ];

        foreach (array_keys(array_filter($addOns)) as $addOn) {
            $addOnPrice += PriceList::addOn($addOn, $tier);
        }

        $runs = 0;
        $articlePrices = 0;

        foreach ($items as $item) {
            if (! $item instanceof RunPlan) {
                throw new InvalidInputException('The plan must hold RunPlan objects, see Client::plan()');
            }

            $runs++;
            $articlePrices += PriceList::article($item->collector, $tier);
        }

        // Summed in whole millionths of a dollar, so there are no rounding errors
        $startFees = $runs * PriceList::RUN_START;
        $bounded = $limit > 0 || $runs === 0;
        $maxArticles = $bounded ? $runs * $limit : null;

        return new CostEstimate(
            tier: $tier,
            runs: $runs,
            startFeesUsd: self::usd($startFees),
            maxArticles: $maxArticles,
            maxArticlesUsd: $bounded ? self::usd($articlePrices * $limit) : null,
            maxAddOnsUsd: $bounded ? self::usd($addOnPrice * (int) $maxArticles) : null,
            maxTotalUsd: $bounded ? self::usd($startFees + ($articlePrices + $addOnPrice * $runs) * $limit) : null,
        );
    }

    private static function usd(int $millionths): float
    {
        return $millionths / 1_000_000;
    }
}
