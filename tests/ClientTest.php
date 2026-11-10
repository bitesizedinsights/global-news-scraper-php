<?php

declare(strict_types=1);

use GlobalNewsScraper\ArticleType;
use GlobalNewsScraper\Collector;
use GlobalNewsScraper\Config;
use GlobalNewsScraper\Exception\InvalidInputException;
use GlobalNewsScraper\Exception\MissingApiKeyException;
use GlobalNewsScraper\Source;
use GlobalNewsScraper\SourceMap;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Collection;

const US = 'bite-sized-insights~us-news-collector';
const UK = 'bite-sized-insights~uk-news-aggregator';
const TW = 'bite-sized-insights~taiwan-english-news-collector';

test('scrape runs the collector, maps the dataset and sets the collection', function () {
    $history = [];
    $client = makeClient([US => ['items' => [article('https://a.test/1'), article('https://a.test/2')]]], $history);

    $result = $client->scrape([Source::NewYorkTimes, Source::Reuters]);

    expect($result->isComplete())->toBeTrue()
        ->and($result->entries)->toBeInstanceOf(Collection::class)
        ->and($result->entries->pluck('url')->all())->toBe(['https://a.test/1', 'https://a.test/2'])
        ->and($result->entries->pluck('collection')->unique()->all())->toBe(['us'])
        ->and($history)->toHaveCount(2);

    $post = $history[0]['request'];
    expect($post->getMethod())->toBe('POST')
        ->and($post->getUri()->getPath())->toBe('/v2/acts/'.US.'/runs')
        ->and($post->getHeaderLine('Authorization'))->toBe('Bearer test-token');

    $get = $history[1]['request'];
    expect($get->getMethod())->toBe('GET')
        ->and($get->getUri()->getPath())->toBe('/v2/datasets/ds-'.US.'/items')
        ->and($get->getHeaderLine('Authorization'))->toBe('Bearer test-token');
});

test('only the requested sources are switched on for a collector', function () {
    $history = [];
    $client = makeClient([US => []], $history);

    $client->scrape([Source::NewYorkTimes, Source::Reuters]);

    $body = sentBody($history[0]['request']);

    expect(array_keys($body))->toBe(SourceMap::BY_COLLECTOR['us'])
        ->and(array_keys(array_filter($body)))->toBe(['new_york_times', 'reuters']);
});

test('scrape accepts a collection of sources', function () {
    $history = [];
    $client = makeClient([UK => ['items' => [article('https://a.test/uk')]]], $history);

    $result = $client->scrape(collect([Source::BbcNews]));

    expect($result->entries)->toHaveCount(1)->and($result->entries->first()->collection)->toBe('uk');
});

test('collectors run separately and results are merged in plan order (smallest collector first)', function () {
    $history = [];
    $client = makeClient([
        US => ['items' => [article('https://a.test/us')]],
        UK => ['items' => [article('https://a.test/uk')]],
        TW => ['items' => [article('https://a.test/tw', ['type' => 'video'])]],
    ], $history);

    $result = $client->scrape([Source::BbcNews, Source::Reuters, Source::TaipeiTimes]);

    expect($result->entries->map(fn ($a) => [$a->collection, $a->url])->all())->toBe([
        ['tw-en', 'https://a.test/tw'],
        ['uk', 'https://a.test/uk'],
        ['us', 'https://a.test/us'],
    ])->and($result->entries->first()->type)->toBe(ArticleType::Video);

    $paths = array_map(fn ($h) => $h['request']->getUri()->getPath(), array_filter(
        $history,
        fn ($h) => $h['request']->getMethod() === 'POST',
    ));
    expect(array_values($paths))->toBe(['/v2/acts/'.TW.'/runs', '/v2/acts/'.UK.'/runs', '/v2/acts/'.US.'/runs']);
});

test('merged results are deduplicated by URL', function () {
    $client = makeClient([
        US => ['items' => [article('https://a.test/same'), article('https://a.test/us')]],
        UK => ['items' => [article('https://a.test/same'), article('https://a.test/uk')]],
    ]);

    $result = $client->scrape([Source::BbcNews, Source::Reuters, Source::NewYorkTimes]);

    expect($result->entries->pluck('url')->all())->toBe([
        'https://a.test/same', 'https://a.test/us', 'https://a.test/uk',
    ])->and($result->entries->first()->collection)->toBe('us');
});

test('a shared source is run once on the most specific collector', function () {
    $history = [];
    $client = makeClient(['bite-sized-insights~mexico-news-aggregator' => []], $history);

    $client->scrape([Source::LaJornada]);

    expect($history)->toHaveCount(2)
        ->and($history[0]['request']->getUri()->getPath())
        ->toBe('/v2/acts/bite-sized-insights~mexico-news-aggregator/runs');
});

test('duplicate sources are sent once', function () {
    $history = [];
    $client = makeClient([US => []], $history);

    $client->scrape([Source::Reuters, Source::Reuters]);

    expect(array_keys(array_filter(sentBody($history[0]['request']))))->toBe(['reuters']);
});

test('plan shows the runs without sending a request', function () {
    $history = [];
    $client = makeClient([], $history);

    $plans = $client->plan([Source::BbcNews, Source::Reuters]);

    expect($plans)->toHaveCount(2)
        ->and($plans[0]->collector)->toBe(Collector::Uk)
        ->and($plans[1]->sources)->toBe([Source::Reuters])
        ->and($history)->toBe([]);
});

test('invalid sources throw before any request', function (array|Collection $sources, string $message) {
    $history = [];
    $client = makeClient([], $history);

    expect(fn () => $client->scrape($sources))->toThrow(InvalidInputException::class, $message)
        ->and($history)->toBe([]);
})->with([
    'empty array' => [[], 'At least one source is required'],
    'empty collection' => [new Collection, 'At least one source is required'],
    'plain string' => [['reuters'], 'Sources must be GlobalNewsScraper\Source cases'],
    'mixed' => [[Source::Reuters, 5], 'Sources must be GlobalNewsScraper\Source cases'],
]);

test('a failing collector is reported while the others still return articles', function () {
    $client = makeClient([
        US => ['items' => [article('https://a.test/us')]],
        UK => ['statuses' => ['FAILED']],
    ]);

    $result = $client->scrape([Source::BbcNews, Source::Reuters]);

    expect($result->hasErrors())->toBeTrue()
        ->and($result->entries->pluck('url')->all())->toBe(['https://a.test/us'])
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors->first()->collector)->toBe(Collector::Uk)
        ->and($result->errors->first()->message)->toContain('FAILED');
});

test('collector failures are reported per collector', function (array $actor, string $message) {
    $result = makeClient([US => $actor])->scrape([Source::Reuters]);

    expect($result->entries)->toBeEmpty()
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors->first()->message)->toContain($message);
})->with([
    'rate limit' => [['start' => jsonResponse(429, [])], 'Rate limit exceeded'],
    'server error' => [['start' => jsonResponse(500, ['error' => 'x'])], 'status 500'],
    'malformed json' => [['start' => new Response(200, [], 'not json')], 'malformed JSON'],
    'json scalar' => [['start' => new Response(200, [], '"text"')], 'unexpected JSON'],
    'no run data' => [['start' => jsonResponse(201, ['x' => 1])], 'missing run data'],
    'aborted run' => [['statuses' => ['ABORTED']], 'ABORTED'],
    'timed out run' => [['statuses' => ['TIMED-OUT']], 'TIMED-OUT'],
    'unknown status' => [['statuses' => ['WEIRD']], 'unknown run status'],
    'missing dataset' => [['datasetId' => null], 'missing dataset ID'],
    'dataset error' => [['dataset' => jsonResponse(500, [])], 'status 500'],
    'item not an object' => [['items' => ['text']], 'Invalid dataset item at index 0'],
    'item without url' => [['items' => [article('https://a.test/1'), ['text' => 'x']]], 'item at index 1'],
]);

test('rate limit failures keep the retry-after advice in the error message', function () {
    $result = makeClient([US => ['start' => jsonResponse(429, [], ['Retry-After' => '12'])]])
        ->scrape([Source::Reuters]);

    expect($result->errors->first()->message)->toBe('Rate limit exceeded');
});

test('an empty dataset yields no entries and no error', function () {
    $result = makeClient([US => ['items' => []]])->scrape([Source::Reuters]);

    expect($result->entries)->toBeEmpty()->and($result->isComplete())->toBeTrue();
});

test('a running collector is polled until it succeeds', function () {
    $history = [];
    $client = makeClient([
        US => ['statuses' => ['RUNNING', 'RUNNING', 'SUCCEEDED'], 'items' => [article('https://a.test/1')]],
    ], $history);

    $result = $client->scrape([Source::Reuters]);

    expect($result->entries)->toHaveCount(1)->and($history)->toHaveCount(4);

    $poll = $history[1]['request'];
    expect($poll->getMethod())->toBe('GET')
        ->and($poll->getUri()->getPath())->toBe('/v2/actor-runs/run-'.US)
        ->and($poll->getUri()->getQuery())->toContain('waitForFinish=');
});

test('a run that does not finish in time is reported', function () {
    $client = makeClient([US => ['statuses' => ['RUNNING']]], config: new Config(timeout: 0));

    $result = $client->scrape([Source::Reuters]);

    expect($result->errors->first()->message)->toContain('did not finish within 0 seconds');
});

test('a run without an ID cannot be polled', function () {
    $client = makeClient([US => ['start' => jsonResponse(201, ['data' => ['status' => 'RUNNING']])]]);

    expect($client->scrape([Source::Reuters])->errors->first()->message)->toContain('missing run ID');
});

test('maxConcurrency 2 starts both runs before reading datasets', function () {
    $history = [];
    $client = makeClient([UK => [], US => []], $history, new Config(maxConcurrency: 2));

    $client->scrape([Source::BbcNews, Source::Reuters]);

    $order = array_map(
        fn ($h) => $h['request']->getMethod(),
        $history,
    );
    expect($order)->toBe(['POST', 'POST', 'GET', 'GET']);
});

test('maxConcurrency 1 runs the collectors one after another', function () {
    $history = [];
    $client = makeClient([UK => [], US => []], $history, new Config(maxConcurrency: 1));

    $client->scrape([Source::BbcNews, Source::Reuters]);

    $order = array_map(fn ($h) => $h['request']->getMethod(), $history);
    expect($order)->toBe(['POST', 'GET', 'POST', 'GET']);
});

test('setApifyKey is fluent and the key is sent as bearer token', function () {
    $history = [];
    $client = makeClient([US => []], $history, apiKey: null);

    expect($client->setApifyKey('my-key'))->toBe($client);

    $client->scrape([Source::Reuters]);

    expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer my-key');
});

/**
 * @param  callable(): void  $callback
 */
function withEnv(?string $apiToken, ?string $token, callable $callback): void
{
    $previous = [getenv('APIFY_API_TOKEN'), getenv('APIFY_TOKEN')];

    foreach ([['APIFY_API_TOKEN', $apiToken], ['APIFY_TOKEN', $token]] as [$name, $value]) {
        putenv($value === null ? $name : "{$name}={$value}");
    }

    try {
        $callback();
    } finally {
        foreach ([['APIFY_API_TOKEN', $previous[0]], ['APIFY_TOKEN', $previous[1]]] as [$name, $value]) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }
}

test('without setApifyKey the APIFY_TOKEN environment variable is used', function () {
    withEnv(null, 'env-token', function () {
        $history = [];
        makeClient([US => []], $history, apiKey: null)->scrape([Source::Reuters]);

        expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer env-token');
    });
});

test('APIFY_API_TOKEN wins over APIFY_TOKEN', function () {
    withEnv('primary', 'secondary', function () {
        $history = [];
        makeClient([US => []], $history, apiKey: null)->scrape([Source::Reuters]);

        expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer primary');
    });
});

test('setApifyKey wins over the environment', function () {
    withEnv('primary', 'secondary', function () {
        $history = [];
        makeClient([US => []], $history, apiKey: 'explicit')->scrape([Source::Reuters]);

        expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer explicit');
    });
});

test('without any key the first call throws before a request is sent', function () {
    withEnv(null, null, function () {
        $history = [];
        $client = makeClient([US => []], $history, apiKey: null);

        expect(fn () => $client->scrape([Source::Reuters]))->toThrow(MissingApiKeyException::class)
            ->and($history)->toBe([]);
    });
});

test('searchQuery is sent to every collector run', function () {
    $history = [];
    $client = makeClient([UK => [], US => []], $history);

    $client->scrape([Source::BbcNews, Source::Reuters], searchQuery: '  climate  ');

    $posts = array_values(array_filter($history, fn ($h) => $h['request']->getMethod() === 'POST'));

    expect($posts)->toHaveCount(2)
        ->and(sentBody($posts[0]['request'])['search_query'])->toBe('climate')
        ->and(sentBody($posts[1]['request'])['search_query'])->toBe('climate');
});

test('searchQuery is omitted when not set', function () {
    $history = [];
    makeClient([US => []], $history)->scrape([Source::Reuters]);

    expect(sentBody($history[0]['request']))->not->toHaveKey('search_query');
});

test('an invalid searchQuery throws before any request', function (string $query) {
    $history = [];
    $client = makeClient([], $history);

    expect(fn () => $client->scrape([Source::Reuters], searchQuery: $query))
        ->toThrow(InvalidInputException::class, 'searchQuery must be 1 to 100 characters')
        ->and($history)->toBe([]);
})->with(['empty' => [''], 'blank' => ['   '], 'too long' => [str_repeat('a', 101)]]);

test('a searchQuery of 100 characters is accepted', function () {
    $history = [];
    makeClient([US => []], $history)->scrape([Source::Reuters], searchQuery: str_repeat('é', 100));

    expect(mb_strlen(sentBody($history[0]['request'])['search_query']))->toBe(100);
});

test('startDate and endDate are sent as YYYY-MM-DD', function () {
    $history = [];
    $client = makeClient([US => []], $history);

    $client->scrape([Source::Reuters], startDate: '2026-01-05', endDate: new DateTimeImmutable('2026-02-10 13:45'));

    $body = sentBody($history[0]['request']);
    expect($body['start_date'])->toBe('2026-01-05')->and($body['end_date'])->toBe('2026-02-10');
});

test('dates are omitted when not set', function () {
    $history = [];
    makeClient([US => []], $history)->scrape([Source::Reuters]);

    expect(sentBody($history[0]['request']))->not->toHaveKey('start_date')->not->toHaveKey('end_date');
});

test('invalid dates throw before any request', function (array $dates, string $message) {
    $history = [];
    $client = makeClient([], $history);

    expect(fn () => $client->scrape([Source::Reuters], ...$dates))
        ->toThrow(InvalidInputException::class, $message)
        ->and($history)->toBe([]);
})->with([
    'wrong format' => [['startDate' => '05.01.2026'], 'startDate must be a date in YYYY-MM-DD format'],
    'impossible day' => [['endDate' => '2026-02-30'], 'endDate must be a date in YYYY-MM-DD format'],
    'with time' => [['startDate' => '2026-01-05 10:00:00'], 'startDate must be a date in YYYY-MM-DD format'],
    'end before start' => [['startDate' => '2026-03-01', 'endDate' => '2026-02-01'], 'endDate must not be before'],
]);

test('startDate in the future and endDate older than 5 years are rejected', function () {
    $client = makeClient([]);
    $future = (new DateTimeImmutable('+2 days'))->format('Y-m-d');
    $old = (new DateTimeImmutable('-6 years'))->format('Y-m-d');

    expect(fn () => $client->scrape([Source::Reuters], startDate: $future))
        ->toThrow(InvalidInputException::class, 'startDate must not be in the future')
        ->and(fn () => $client->scrape([Source::Reuters], endDate: $old))
        ->toThrow(InvalidInputException::class, 'endDate must not be older than 5 years');
});

test('today is a valid startDate and endDate', function () {
    $history = [];
    $today = gmdate('Y-m-d');

    makeClient([US => []], $history)->scrape([Source::Reuters], startDate: $today, endDate: $today);

    expect(sentBody($history[0]['request'])['start_date'])->toBe($today);
});

test('limit is sent to every collector run', function () {
    $history = [];
    $client = makeClient([UK => [], US => []], $history);

    $client->scrape([Source::BbcNews, Source::Reuters], limit: 25);

    $posts = array_values(array_filter($history, fn ($h) => $h['request']->getMethod() === 'POST'));

    expect(sentBody($posts[0]['request'])['limit'])->toBe(25)
        ->and(sentBody($posts[1]['request'])['limit'])->toBe(25);
});

test('limit 0 means unlimited and is sent as 0', function () {
    $history = [];
    makeClient([US => []], $history)->scrape([Source::Reuters], limit: 0);

    expect(sentBody($history[0]['request']))->toHaveKey('limit', 0);
});

test('limit is omitted by default so the Actor default applies', function () {
    $history = [];
    makeClient([US => []], $history)->scrape([Source::Reuters]);

    expect(sentBody($history[0]['request']))->not->toHaveKey('limit');
});

test('limit outside 0 to 10000 throws before any request', function (int $limit) {
    $history = [];
    $client = makeClient([], $history);

    expect(fn () => $client->scrape([Source::Reuters], limit: $limit))
        ->toThrow(InvalidInputException::class, 'limit must be between 0 and 10000')
        ->and($history)->toBe([]);
})->with([-1, 10001]);

test('limit 10000 is accepted', function () {
    $history = [];
    makeClient([US => []], $history)->scrape([Source::Reuters], limit: 10000);

    expect(sentBody($history[0]['request'])['limit'])->toBe(10000);
});

test('analysis flags are sent to every collector run when enabled', function (string $param, string $flag) {
    $history = [];
    $client = makeClient([UK => [], US => []], $history);

    $client->scrape([Source::BbcNews, Source::Reuters], ...[$param => true]);

    $posts = array_values(array_filter($history, fn ($h) => $h['request']->getMethod() === 'POST'));

    expect(sentBody($posts[0]['request'])[$flag])->toBeTrue()
        ->and(sentBody($posts[1]['request'])[$flag])->toBeTrue();
})->with([
    'sentiment' => ['withSentiment', 'with_sentiment'],
    'tags' => ['withTags', 'with_tags'],
    'summary' => ['withSummary', 'with_summary'],
]);

test('analysis flags are omitted by default', function () {
    $history = [];
    makeClient([US => []], $history)->scrape([Source::Reuters]);

    expect(array_keys(sentBody($history[0]['request'])))->not->toContain('with_sentiment', 'with_tags', 'with_summary');
});

test('enriched articles expose the analysis results', function () {
    $client = makeClient([UK => ['items' => [loadFixture('article_enriched')]]]);

    $article = $client->scrape([Source::BbcNews], withSentiment: true, withTags: true, withSummary: true)
        ->entries->first();

    expect($article->hasEnrichment())->toBeTrue()
        ->and($article->sentiment)->toBe(['sentiment' => 'positive'])
        ->and($article->tags)->toBe(['tags' => ['politics']])
        ->and($article->summary)->toBe(['summary' => 'Short summary.']);
});
