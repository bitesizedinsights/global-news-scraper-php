<?php

declare(strict_types=1);

namespace GlobalNewsScraper;

/**
 * Apify discount tier of your account, which follows your Apify plan. The value is Apify's tier name.
 */
enum PricingTier: string
{
    case Free = 'FREE';
    case Bronze = 'BRONZE';
    case Silver = 'SILVER';
    case Gold = 'GOLD';
    case Platinum = 'PLATINUM';
    case Diamond = 'DIAMOND';
}
