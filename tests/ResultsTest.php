<?php

declare(strict_types=1);

use GlobalNewsScraper\Collector;
use GlobalNewsScraper\DTO\Article;
use GlobalNewsScraper\DTO\RunBatch;
use GlobalNewsScraper\DTO\RunPlan;
use GlobalNewsScraper\DTO\ScrapeResult;
use GlobalNewsScraper\Exception\InvalidInputException;
use GlobalNewsScraper\Results;
use GlobalNewsScraper\Source;
use Illuminate\Support\Collection;

const US_SLUG = 'bite-sized-insights~us-news-scraper';
const UK_SLUG = 'bite-sized-insights~uk-news-scraper';

/** @param  list<array{0: string, 1: string}>  $rows  Collector key and source domain of each article. */
function countedResult(array $rows): ScrapeResult
{
    $entries = [];

    foreach ($rows as $index => [$collection, $domain]) {
        $entries[] = new Article(url: "https://a.test/{$index}", collection: $collection, sourceDomain: $domain);
    }

    return new ScrapeResult(new Collection($entries), new Collection);
}

/** @return list<RunPlan> */
function usUkPlan(): array
{
    return [new RunPlan(Collector::Us, [Source::NewYorkTimes]), new RunPlan(Collector::Uk, [Source::BbcNews])];
}

it('counts articles per collector in entry order without a plan', function () {
    $result = countedResult([['uk', 'bbc.co.uk'], ['us', 'npr.org'], ['uk', 'bbc.co.uk']]);

    expect(Results::countsByCollector($result))->toBe(['uk' => 2, 'us' => 1]);
});

it('lists planned collectors in plan order with zeros, then unplanned ones', function () {
    $result = countedResult([['jp-en', 'nippon.com'], ['uk', 'bbc.co.uk']]);

    expect(Results::countsByCollector($result, usUkPlan()))->toBe(['us' => 0, 'uk' => 1, 'jp-en' => 1])
        ->and(Results::countsByCollector($result, new Collection(usUkPlan())))
        ->toBe(['us' => 0, 'uk' => 1, 'jp-en' => 1]);
});

it('lists a collector that is planned twice once', function () {
    $counts = Results::countsByCollector(countedResult([]), [...usUkPlan(), ...usUkPlan()]);

    expect($counts)->toBe(['us' => 0, 'uk' => 0]);
});

it('counts nothing in an empty result', function () {
    expect(Results::countsByCollector(countedResult([])))->toBe([])
        ->and(Results::countsByDomain(countedResult([])))->toBe([]);
});

it('rejects a plan that does not hold RunPlan objects', function (mixed $item) {
    Results::countsByCollector(countedResult([]), [$item]);
})->with([[Source::BbcNews], [Collector::Us], ['us'], [null]])->throws(InvalidInputException::class, 'RunPlan');

it('counts articles per normalised domain and skips articles without one', function () {
    $result = countedResult([
        ['us', 'www.NPR.org'], ['us', 'npr.org.'], ['uk', 'feeds.npr.org'], ['uk', ''], ['us', 'www2.npr.org'],
    ]);

    expect(Results::countsByDomain($result))->toBe(['npr.org' => 2, 'feeds.npr.org' => 1, 'www2.npr.org' => 1]);
});

it('counts the merged result of scrape(), where a duplicate stays with the earlier collector', function () {
    $client = makeClient([
        US_SLUG => ['items' => [article('https://a.test/1'), article('https://a.test/2')]],
        UK_SLUG => ['items' => [article('https://www.a.test/1/?utm_source=x')]],
    ]);
    $sources = [Source::NewYorkTimes, Source::BbcNews];
    $plan = $client->plan($sources);

    $counts = Results::countsByCollector($client->scrape($sources), $plan);

    expect(array_sum($counts))->toBe(2)
        ->and($counts)->toHaveKeys(['us', 'uk'])
        ->and(min($counts))->toBe(0);
});

it('counts the result of start() and results() like the one of scrape()', function () {
    $actors = [
        US_SLUG => ['items' => [article('https://a.test/1', ['source_domain' => 'www.nytimes.com'])]],
        UK_SLUG => ['items' => [
            article('https://b.test/1', ['source_domain' => 'www.bbc.com']),
            article('https://b.test/2', ['source_domain' => 'www.bbc.com']),
        ]],
    ];
    $client = makeClient($actors);
    $sources = [Source::NewYorkTimes, Source::BbcNews];
    $batch = RunBatch::fromArray($client->start($sources)->toArray());

    $result = $client->results($client->wait($batch, 0));
    $counts = Results::countsByCollector($result, $client->plan($sources));

    expect($counts)->toBe(Results::countsByCollector($client->scrape($sources), $client->plan($sources)))
        ->and($counts['us'])->toBe(1)
        ->and($counts['uk'])->toBe(2)
        ->and(Results::countsByDomain($result))->toEqualCanonicalizing(['nytimes.com' => 1, 'bbc.com' => 2]);
});
