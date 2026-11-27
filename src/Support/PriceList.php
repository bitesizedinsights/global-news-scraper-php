<?php

declare(strict_types=1);

namespace GlobalNewsScraper\Support;

use GlobalNewsScraper\Collector;
use GlobalNewsScraper\PricingTier;

/**
 * Pay-per-event prices of the collector Actors in millionths of a US dollar, copied from the `pricingModel` of their
 * `actor.json`. The run start and the add-ons cost the same on every collector, only the article price differs.
 *
 * @internal
 */
final class PriceList
{
    /** Per run, the same on every tier. */
    public const RUN_START = 200;

    private const DEFAULT_ARTICLE_PRICE = 1800;

    /** @var array<string, int> Free tier article price of the collectors that differ from the default. */
    private const ARTICLE_PRICES = [
        'jp-en' => 1000,
        'id' => 1200,
        'id-en' => 1200,
        'arab' => 1400,
        'es' => 1400,
        'fr' => 1400,
        'sea' => 2000,
    ];

    /**
     * Article price by Free tier price, in tier order: Free, Bronze, Silver, Gold, Platinum, Diamond.
     *
     * @var array<int, list<int>>
     */
    private const ARTICLE_TIERS = [
        1000 => [1000, 950, 850, 650, 650, 650],
        1200 => [1200, 1140, 1020, 780, 780, 780],
        1400 => [1400, 1330, 1190, 910, 910, 910],
        1800 => [1800, 1710, 1530, 1170, 1170, 1170],
        2000 => [2000, 1900, 1700, 1300, 1300, 1300],
    ];

    /** @var array<string, list<int>> Price per enriched article, in tier order. */
    private const ADD_ON_TIERS = [
        'with_sentiment' => [200, 190, 170, 130, 130, 130],
        'with_tags' => [1500, 1425, 1275, 975, 975, 975],
        'with_summary' => [2500, 2375, 2125, 1625, 1625, 1625],
        'with_5w1h' => [4000, 3800, 3400, 2600, 2600, 2600],
        'with_language_detection' => [100, 95, 85, 65, 65, 65],
        'with_fix_text' => [1250, 1187, 1063, 813, 813, 813],
    ];

    public static function article(Collector $collector, PricingTier $tier): int
    {
        $base = self::ARTICLE_PRICES[$collector->value] ?? self::DEFAULT_ARTICLE_PRICE;

        return self::ARTICLE_TIERS[$base][self::index($tier)];
    }

    /** @param  string  $addOn  Input field of the Actors, e.g. `with_tags`. */
    public static function addOn(string $addOn, PricingTier $tier): int
    {
        return self::ADD_ON_TIERS[$addOn][self::index($tier)];
    }

    private static function index(PricingTier $tier): int
    {
        return match ($tier) {
            PricingTier::Free => 0,
            PricingTier::Bronze => 1,
            PricingTier::Silver => 2,
            PricingTier::Gold => 3,
            PricingTier::Platinum => 4,
            PricingTier::Diamond => 5,
        };
    }
}
