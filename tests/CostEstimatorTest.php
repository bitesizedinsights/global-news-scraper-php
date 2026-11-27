<?php

declare(strict_types=1);

use GlobalNewsScraper\Client;
use GlobalNewsScraper\Collector;
use GlobalNewsScraper\CostEstimator;
use GlobalNewsScraper\DTO\RunPlan;
use GlobalNewsScraper\Exception\InvalidInputException;
use GlobalNewsScraper\PricingTier;
use GlobalNewsScraper\Source;
use GlobalNewsScraper\Support\PriceList;
use Illuminate\Support\Collection;

function runOf(Collector $collector): RunPlan
{
    return new RunPlan($collector, []);
}

test('the README examples add up', function () {
    $one = CostEstimator::estimate(runOf(Collector::Us), limit: 100);
    $plan = [runOf(Collector::Us), runOf(Collector::Uk), runOf(Collector::JpEn)];
    $three = CostEstimator::estimate($plan, limit: 50);
    $enriched = CostEstimator::estimate(new Collection($plan), limit: 50, withSentiment: true, withSummary: true);

    expect($one->maxTotalUsd)->toBe(0.1802)
        ->and($one->tier)->toBe(PricingTier::Free)
        ->and($three->runs)->toBe(3)
        ->and($three->startFeesUsd)->toBe(0.0006)
        ->and($three->maxArticles)->toBe(150)
        ->and($three->maxArticlesUsd)->toBe(0.23)
        ->and($three->maxAddOnsUsd)->toBe(0.0)
        ->and($three->maxTotalUsd)->toBe(0.2306)
        ->and($enriched->maxAddOnsUsd)->toBe(0.405)
        ->and($enriched->maxTotalUsd)->toBe(0.6356)
        ->and($enriched->isBounded())->toBeTrue();
});

test('all add-ons by tier', function (PricingTier $tier, float $article, float $addOns) {
    $estimate = CostEstimator::estimate(runOf(Collector::Us), 1000, $tier, true, true, true, true, true, true);

    expect($estimate->maxArticlesUsd)->toBe($article)
        ->and($estimate->maxAddOnsUsd)->toBe($addOns)
        ->and($estimate->startFeesUsd)->toBe(0.0002);
})->with([
    [PricingTier::Free, 1.8, 9.55],
    [PricingTier::Bronze, 1.71, 9.072],
    [PricingTier::Silver, 1.53, 8.118],
    [PricingTier::Gold, 1.17, 6.208],
    [PricingTier::Platinum, 1.17, 6.208],
    [PricingTier::Diamond, 1.17, 6.208],
]);

test('article prices differ by collector', function (Collector $collector, float $usd) {
    expect(CostEstimator::estimate(runOf($collector), 1000)->maxArticlesUsd)->toBe($usd);
})->with([
    [Collector::JpEn, 1.0], [Collector::Id, 1.2], [Collector::IdEn, 1.2], [Collector::Es, 1.4],
    [Collector::Fr, 1.4], [Collector::Arab, 1.4], [Collector::Sea, 2.0], [Collector::Defense, 1.8],
]);

test('every collector has a price on every tier', function () {
    foreach (Collector::cases() as $collector) {
        foreach (PricingTier::cases() as $tier) {
            expect(PriceList::article($collector, $tier))->toBeGreaterThan(0)->toBeLessThanOrEqual(2000);
        }
    }
});

test('a null limit is the Actor default of 100 and 0 has no upper bound', function () {
    $default = CostEstimator::estimate(runOf(Collector::Us));
    $unlimited = CostEstimator::estimate([runOf(Collector::Us), runOf(Collector::Uk)], limit: 0, withTags: true);

    expect($default->maxArticles)->toBe(100)
        ->and($unlimited->runs)->toBe(2)
        ->and($unlimited->startFeesUsd)->toBe(0.0004)
        ->and($unlimited->maxArticles)->toBeNull()
        ->and($unlimited->maxArticlesUsd)->toBeNull()
        ->and($unlimited->maxAddOnsUsd)->toBeNull()
        ->and($unlimited->maxTotalUsd)->toBeNull()
        ->and($unlimited->isBounded())->toBeFalse();
});

test('an empty plan costs nothing, also without a limit', function () {
    $estimate = CostEstimator::estimate([], limit: 0);

    expect($estimate->runs)->toBe(0)->and($estimate->maxTotalUsd)->toBe(0.0);
});

test('it takes the plan of the client without a request', function () {
    $history = [];
    $client = makeClient([], $history, apiKey: null);
    $plan = $client->plan([Source::WashingtonPost, Source::BbcNews]);

    expect(CostEstimator::estimate($plan, 10)->maxTotalUsd)->toBe(0.0364)
        ->and($history)->toBe([])
        ->and($client)->toBeInstanceOf(Client::class);
});

test('invalid input throws', function (array $plan, ?int $limit) {
    expect(fn () => CostEstimator::estimate($plan, $limit))->toThrow(InvalidInputException::class);
})->with([
    'negative limit' => [[], -1],
    'limit too high' => [[], 10001],
    'not a run plan' => [['us'], 10],
]);
