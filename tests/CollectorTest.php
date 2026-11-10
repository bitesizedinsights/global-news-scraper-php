<?php

declare(strict_types=1);

use GlobalNewsScraper\Collector;
use GlobalNewsScraper\Exception\ApiException;
use GlobalNewsScraper\Exception\InvalidInputException;
use GlobalNewsScraper\Exception\MissingApiKeyException;
use GlobalNewsScraper\Exception\RateLimitException;

test('there are 31 collectors with unique keys and actor IDs', function () {
    $cases = Collector::cases();

    expect($cases)->toHaveCount(31)
        ->and(array_unique(array_map(fn (Collector $c) => $c->value, $cases)))->toHaveCount(31)
        ->and(array_unique(array_map(fn (Collector $c) => $c->actorId(), $cases)))->toHaveCount(31);
});

test('collector maps to its Apify actor', function () {
    expect(Collector::Us->actorId())->toBe('bite-sized-insights/us-news-scraper')
        ->and(Collector::JpEn->value)->toBe('jp-en')
        ->and(Collector::JpEn->actorName())->toBe('news-collector-jp-en')
        ->and(Collector::tryFrom('xx'))->toBeNull();
});

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
