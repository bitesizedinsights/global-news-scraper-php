<?php

declare(strict_types=1);

use GlobalNewsScraper\Collector;
use GlobalNewsScraper\DTO\Article;
use GlobalNewsScraper\DTO\CollectorError;
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

/** @return list<RunPlan> */
function fourRunPlan(): array
{
    return [
        ...usUkPlan(),
        new RunPlan(Collector::JpEn, [Source::JapanTimes]),
        new RunPlan(Collector::TwEn, [Source::TaipeiTimes]),
    ];
}

it('counts the planned collector runs once per collector', function () {
    expect(Results::inputCount([]))->toBe(0)
        ->and(Results::inputCount(usUkPlan()))->toBe(2)
        ->and(Results::inputCount(new Collection([...usUkPlan(), ...usUkPlan()])))->toBe(2);
});

it('gives 1-based plan positions of collectors without articles', function () {
    $result = countedResult([['uk', 'bbc.co.uk'], ['uk', 'bbc.co.uk']]);

    expect(Results::missingIndexes($result, fourRunPlan()))->toBe([1, 3, 4])
        ->and(Results::isComplete($result, fourRunPlan()))->toBeFalse();
});

it('takes positions in the deduplicated plan', function () {
    $plan = [...usUkPlan(), ...usUkPlan(), new RunPlan(Collector::JpEn, [Source::JapanTimes])];

    expect(Results::missingIndexes(countedResult([['us', 'npr.org']]), $plan))->toBe([2, 3]);
});

it('is complete when every planned collector has an article, whatever else is in the result', function () {
    $result = countedResult([['uk', 'bbc.co.uk'], ['us', 'npr.org'], ['jp-en', 'nippon.com']]);

    expect(Results::isComplete($result, usUkPlan()))->toBeTrue()
        ->and(Results::missingIndexes($result, usUkPlan()))->toBe([])
        ->and(Results::isComplete(countedResult([]), []))->toBeTrue();
});

it('tells failed collectors from empty ones, in plan order', function () {
    $result = new ScrapeResult(
        countedResult([['uk', 'bbc.co.uk']])->entries,
        new Collection([
            new CollectorError(Collector::TwEn, 'boom'),
            new CollectorError(Collector::Us, 'boom'),
            new CollectorError(Collector::Es, 'not planned'),
        ]),
    );

    expect(Results::failedCollectors($result, fourRunPlan()))->toBe([Collector::Us, Collector::TwEn])
        ->and(Results::emptyCollectors($result, fourRunPlan()))->toBe([Collector::JpEn])
        ->and(Results::missingIndexes($result, fourRunPlan()))->toBe([1, 3, 4]);
});

it('keeps a collector with an error and articles out of the missing and empty ones', function () {
    $result = new ScrapeResult(
        countedResult([['us', 'npr.org']])->entries,
        new Collection([new CollectorError(Collector::Us, 'one of two merged runs failed')]),
    );

    expect(Results::failedCollectors($result, usUkPlan()))->toBe([Collector::Us])
        ->and(Results::emptyCollectors($result, usUkPlan()))->toBe([Collector::Uk])
        ->and(Results::missingIndexes($result, usUkPlan()))->toBe([2]);
});

it('is not complete for an empty successful run although the result has no error', function () {
    $client = makeClient([US_SLUG => ['items' => [article('https://a.test/1')]], UK_SLUG => []]);
    $sources = [Source::NewYorkTimes, Source::BbcNews];
    $plan = $client->plan($sources);
    $result = $client->scrape($sources);
    $uk = array_search(Collector::Uk, array_column($plan, 'collector'), true);

    expect($result->isComplete())->toBeTrue()
        ->and(Results::isComplete($result, $plan))->toBeFalse()
        ->and(Results::missingIndexes($result, $plan))->toBe([$uk + 1])
        ->and(Results::emptyCollectors($result, $plan))->toBe([Collector::Uk])
        ->and(Results::failedCollectors($result, $plan))->toBe([]);
});

it('reports a collector whose run failed in scrape() as failed, not empty', function () {
    $client = makeClient([
        US_SLUG => ['items' => [article('https://a.test/1')]],
        UK_SLUG => ['statuses' => ['FAILED']],
    ]);
    $sources = [Source::NewYorkTimes, Source::BbcNews];
    $result = $client->scrape($sources);

    expect(Results::failedCollectors($result, $client->plan($sources)))->toBe([Collector::Uk])
        ->and(Results::emptyCollectors($result, $client->plan($sources)))->toBe([]);
});

it('reports start errors and unfinished runs of results() as failed', function () {
    $client = makeClient([
        US_SLUG => ['start' => jsonResponse(500, ['error' => ['type' => 'x', 'message' => 'y']])],
        UK_SLUG => ['statuses' => ['RUNNING', 'RUNNING']],
    ]);
    $sources = [Source::NewYorkTimes, Source::BbcNews];
    $plan = $client->plan($sources);
    $result = $client->results($client->start($sources));

    expect(Results::failedCollectors($result, $plan))->toEqualCanonicalizing([Collector::Us, Collector::Uk])
        ->and(Results::emptyCollectors($result, $plan))->toBe([])
        ->and(Results::missingIndexes($result, $plan))->toBe([1, 2]);
});

it('lists a run left out of results() as empty, as documented', function () {
    $client = makeClient([
        US_SLUG => ['items' => [article('https://a.test/1')]],
        UK_SLUG => ['items' => [article('https://a.test/2')]],
    ]);
    $sources = [Source::NewYorkTimes, Source::BbcNews];
    $us = $client->start($sources)->runs->first(fn ($run) => $run->collector === Collector::Us);
    $result = $client->results($us);

    expect(Results::emptyCollectors($result, $client->plan($sources)))->toBe([Collector::Uk]);
});

it('rejects a source list passed as plan in every index method', function (string $method) {
    $arguments = $method === 'inputCount' ? [[Source::BbcNews]] : [countedResult([]), [Source::BbcNews]];

    Results::$method(...$arguments);
})->with(['inputCount', 'missingIndexes', 'isComplete', 'failedCollectors', 'emptyCollectors'])
    ->throws(InvalidInputException::class);
