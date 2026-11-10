<?php

declare(strict_types=1);

use GlobalNewsScraper\Client;
use GlobalNewsScraper\Config;
use GlobalNewsScraper\Source;

/**
 * Live test against the real collector Actors. Costs a fraction of a cent: two collectors, one article each.
 *
 * Runs only with APIFY_LIVE_TEST=1 and a token in APIFY_API_TOKEN or APIFY_TOKEN.
 */
function liveToken(): ?string
{
    foreach (['APIFY_API_TOKEN', 'APIFY_TOKEN'] as $name) {
        $value = getenv($name);

        if (is_string($value) && $value !== '') {
            return $value;
        }
    }

    return null;
}

function liveEnabled(): bool
{
    return getenv('APIFY_LIVE_TEST') === '1' && liveToken() !== null;
}

test('live: scrapes two sources from two collectors and merges them', function () {
    $token = liveToken();
    assert($token !== null);

    $client = (new Client(new Config(timeout: 600)))->setApifyKey($token);

    $result = $client->scrape([Source::JapanTimes, Source::BbcNews], limit: 1);

    $failures = $result->errors->map(fn ($e) => "{$e->collector->value}: {$e->message}")->implode('; ');

    expect($result->isComplete())->toBeTrue($failures)
        ->and($result->entries)->not->toBeEmpty()
        ->and($result->entries->pluck('collection')->unique()->sort()->values()->all())->toBe(['jp-en', 'uk'])
        ->and($result->entries->pluck('url')->unique())->toHaveCount($result->entries->count());

    foreach ($result->entries as $article) {
        expect($article->url)->toStartWith('http')
            ->and($article->title)->toBeString()->not->toBeEmpty()
            ->and($article->text)->not->toBeEmpty()
            ->and($article->sourceDomain)->not->toBeEmpty();
    }
})->skip(fn () => ! liveEnabled(), 'Set APIFY_LIVE_TEST=1 and APIFY_API_TOKEN (or APIFY_TOKEN) to run live tests');
