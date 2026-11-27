<?php

declare(strict_types=1);

use GlobalNewsScraper\Client;
use GlobalNewsScraper\Collector;
use GlobalNewsScraper\Exception\ApiException;
use GlobalNewsScraper\Exception\InvalidInputException;
use GlobalNewsScraper\Exception\MissingApiKeyException;
use GlobalNewsScraper\Exception\RateLimitException;
use GlobalNewsScraper\Source;
use GlobalNewsScraper\SourceGroup;

test('there are 52 collectors with unique keys and actor IDs', function () {
    $cases = Collector::cases();

    expect($cases)->toHaveCount(52)
        ->and(array_unique(array_map(fn (Collector $c) => $c->value, $cases)))->toHaveCount(52)
        ->and(array_unique(array_map(fn (Collector $c) => $c->actorId(), $cases)))->toHaveCount(52);
});

test('collector maps to its Apify actor', function () {
    expect(Collector::Us->actorId())->toBe('bite-sized-insights/us-news-scraper')
        ->and(Collector::JpEn->value)->toBe('jp-en')
        ->and(Collector::JpEn->actorName())->toBe('news-collector-jp-en')
        ->and(Collector::tryFrom('xx'))->toBeNull();
});

test('topic collectors map to their Apify actors and offer English sources', function (
    Collector $collector,
    string $slug,
    Source $source,
) {
    expect($collector->actorId())->toBe("bite-sized-insights/{$slug}")
        ->and($collector->isRegional())->toBeFalse()
        ->and($collector->sources())->toContain($source)
        ->and($source->language())->toBe(SourceGroup::English)
        ->and((new Client)->plan($source)[0]->collector)->toBe($collector);
})->with([
    'defense' => [Collector::Defense, 'defense-news-scraper', Source::BreakingDefense],
    'energy-climate' => [Collector::EnergyClimate, 'energy-climate-news-scraper', Source::CarbonBrief],
    'startups-vc' => [Collector::StartupsVc, 'startup-vc-news-scraper', Source::CrunchbaseNews],
    'supply-chain' => [Collector::SupplyChain, 'supply-chain-news-scraper', Source::Splash247],
    'biopharma' => [Collector::Biopharma, 'biotech-pharma-news-scraper', Source::Biospace],
    'insurance' => [Collector::Insurance, 'insurtech-news-scraper', Source::InsuranceJournal],
    'marketing' => [Collector::Marketing, 'adtech-martech-news-scraper', Source::Digiday],
    'sports-business' => [Collector::SportsBusiness, 'sports-business-news-scraper', Source::Sportico],
]);

test('regional collectors are flagged', function () {
    $regional = array_filter(Collector::cases(), fn (Collector $c) => $c->isRegional());

    expect(array_map(fn (Collector $c) => $c->value, array_values($regional)))
        ->toBe(['latam', 'sea', 'arab', 'scandinavian']);
});

test('exceptions have the expected hierarchy and defaults', function () {
    expect(new MissingApiKeyException)->toBeInstanceOf(ApiException::class)
        ->and((new MissingApiKeyException)->getMessage())->toContain('setApifyKey()')
        ->and((new RateLimitException)->retryAfter)->toBe(60)
        ->and((new RateLimitException('x', 5))->retryAfter)->toBe(5)
        ->and(new InvalidInputException)->toBeInstanceOf(InvalidArgumentException::class);
});
