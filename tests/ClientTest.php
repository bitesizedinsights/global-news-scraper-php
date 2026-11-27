<?php

declare(strict_types=1);

use GlobalNewsScraper\ArticleType;
use GlobalNewsScraper\Client;
use GlobalNewsScraper\Collector;
use GlobalNewsScraper\Config;
use GlobalNewsScraper\DTO\Enrichment\SentimentLabel;
use GlobalNewsScraper\Exception\ApiException;
use GlobalNewsScraper\Exception\InvalidInputException;
use GlobalNewsScraper\Exception\MissingApiKeyException;
use GlobalNewsScraper\Exception\SpendingLimitException;
use GlobalNewsScraper\Source;
use GlobalNewsScraper\SourceMap;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Collection;
use Psr\Http\Message\RequestInterface;

const US = 'bite-sized-insights~us-news-scraper';
const UK = 'bite-sized-insights~uk-news-scraper';
const TW = 'bite-sized-insights~taiwan-english-news-scraper';

test('scrape runs the collector, maps the dataset and sets the collection', function () {
    $history = [];
    $client = makeClient([US => ['items' => [article('https://a.test/1'), article('https://a.test/2')]]], $history);

    $result = $client->scrape([Source::NewYorkTimes, Source::WashingtonPost]);

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

    $client->scrape([Source::NewYorkTimes, Source::WashingtonPost]);

    $body = sentBody($history[0]['request']);

    expect(array_keys($body))->toBe(SourceMap::BY_COLLECTOR['us'])
        ->and(array_keys(array_filter($body)))->toBe(['new_york_times', 'washington_post']);
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

    $result = $client->scrape([Source::BbcNews, Source::WashingtonPost, Source::TaipeiTimes]);

    expect($result->entries->map(fn ($a) => [$a->collection, $a->url])->all())->toBe([
        ['tw-en', 'https://a.test/tw'],
        ['us', 'https://a.test/us'],
        ['uk', 'https://a.test/uk'],
    ])->and($result->entries->first()->type)->toBe(ArticleType::Video);

    $paths = array_map(fn ($h) => $h['request']->getUri()->getPath(), array_filter(
        $history,
        fn ($h) => $h['request']->getMethod() === 'POST',
    ));
    expect(array_values($paths))->toBe(['/v2/acts/'.TW.'/runs', '/v2/acts/'.US.'/runs', '/v2/acts/'.UK.'/runs']);
});

test('merged results are deduplicated by URL', function () {
    $client = makeClient([
        US => ['items' => [article('https://a.test/same'), article('https://a.test/us')]],
        UK => ['items' => [article('https://a.test/same'), article('https://a.test/uk')]],
    ]);

    $result = $client->scrape([Source::BbcNews, Source::WashingtonPost, Source::NewYorkTimes]);

    expect($result->entries->pluck('url')->all())->toBe([
        'https://a.test/same', 'https://a.test/us', 'https://a.test/uk',
    ])->and($result->entries->first()->collection)->toBe('us');
});

test('a shared source is run once on the most specific collector', function () {
    $history = [];
    $client = makeClient(['bite-sized-insights~mexico-news-scraper' => []], $history);

    $client->scrape([Source::LaJornada]);

    expect($history)->toHaveCount(2)
        ->and($history[0]['request']->getUri()->getPath())
        ->toBe('/v2/acts/bite-sized-insights~mexico-news-scraper/runs');
});

test('URL variants are merged into the first article, which keeps its own URL', function () {
    $client = makeClient([
        US => ['items' => [article('https://www.a.test/same/?utm_source=rss'), article('https://a.test/s?id=1')]],
        UK => ['items' => [article('http://a.test/same'), article('https://a.test/s?id=2')]],
    ]);

    $result = $client->scrape([Source::BbcNews, Source::WashingtonPost, Source::NewYorkTimes]);

    expect($result->entries->pluck('url')->all())->toBe([
        'https://www.a.test/same/?utm_source=rss', 'https://a.test/s?id=1', 'https://a.test/s?id=2',
    ])->and($result->entries->first()->collection)->toBe('us');
});

test('duplicate sources are sent once', function () {
    $history = [];
    $client = makeClient([US => []], $history);

    $client->scrape([Source::WashingtonPost, Source::WashingtonPost]);

    expect(array_keys(array_filter(sentBody($history[0]['request']))))->toBe(['washington_post']);
});

test('plan shows the runs without sending a request', function () {
    $history = [];
    $client = makeClient([], $history);

    $plans = $client->plan([Source::BbcNews, Source::WashingtonPost]);

    expect($plans)->toHaveCount(2)
        ->and($plans[0]->collector)->toBe(Collector::Us)
        ->and($plans[1]->sources)->toBe([Source::BbcNews])
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
    'plain string' => [['washington_post'], 'Sources must be Source, SourceGroup or Collector cases'],
    'mixed' => [[Source::WashingtonPost, 5], 'Sources must be Source, SourceGroup or Collector cases'],
]);

test('a failing collector is reported while the others still return articles', function () {
    $client = makeClient([
        US => ['items' => [article('https://a.test/us')]],
        UK => ['statuses' => ['FAILED']],
    ]);

    $result = $client->scrape([Source::BbcNews, Source::WashingtonPost]);

    expect($result->hasErrors())->toBeTrue()
        ->and($result->entries->pluck('url')->all())->toBe(['https://a.test/us'])
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors->first()->collector)->toBe(Collector::Uk)
        ->and($result->errors->first()->message)->toContain('FAILED');
});

test('collector failures are reported per collector', function (array $actor, string $message) {
    $result = makeClient([US => $actor])->scrape([Source::WashingtonPost]);

    expect($result->entries)->toBeEmpty()
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors->first()->message)->toContain($message);
})->with([
    'rate limit' => [['start' => jsonResponse(429, [])], 'rate limit reached'],
    'server error' => [['start' => jsonResponse(500, ['error' => 'x'])], 'HTTP 500'],
    'malformed json' => [['start' => new Response(200, [], 'not json')], 'malformed JSON'],
    'json scalar' => [['start' => new Response(200, [], '"text"')], 'unexpected JSON'],
    'no run data' => [['start' => jsonResponse(201, ['x' => 1])], 'missing run data'],
    'aborted run' => [['statuses' => ['ABORTED']], 'ABORTED'],
    'timed out run' => [['statuses' => ['TIMED-OUT']], 'TIMED-OUT'],
    'unknown status' => [['statuses' => ['WEIRD']], 'unknown run status'],
    'missing dataset' => [['datasetId' => null], 'missing dataset ID'],
    'dataset error' => [['dataset' => jsonResponse(500, [])], 'HTTP 500'],
    'item not an object' => [['items' => ['text']], 'Invalid dataset item at index 0'],
    'item without url' => [['items' => [article('https://a.test/1'), ['text' => 'x']]], 'item at index 1'],
]);

test('rate limit failures keep the retry-after advice in the error message', function () {
    $result = makeClient([US => ['start' => jsonResponse(429, [], ['Retry-After' => '12'])]])
        ->scrape([Source::WashingtonPost]);

    expect($result->errors->first()->message)->toBe('Apify API rate limit reached (retry after 12 seconds)');
});

test('an empty dataset yields no entries and no error', function () {
    $result = makeClient([US => ['items' => []]])->scrape([Source::WashingtonPost]);

    expect($result->entries)->toBeEmpty()->and($result->isComplete())->toBeTrue();
});

test('a running collector is polled until it succeeds', function () {
    $history = [];
    $client = makeClient([
        US => ['statuses' => ['RUNNING', 'RUNNING', 'SUCCEEDED'], 'items' => [article('https://a.test/1')]],
    ], $history);

    $result = $client->scrape([Source::WashingtonPost]);

    expect($result->entries)->toHaveCount(1)->and($history)->toHaveCount(4);

    $poll = $history[1]['request'];
    expect($poll->getMethod())->toBe('GET')
        ->and($poll->getUri()->getPath())->toBe('/v2/actor-runs/run-'.US)
        ->and($poll->getUri()->getQuery())->toContain('waitForFinish=');
});

test('a run that does not finish in time is reported', function () {
    $client = makeClient([US => ['statuses' => ['RUNNING']]], config: new Config(timeout: 0));

    $result = $client->scrape([Source::WashingtonPost]);

    expect($result->errors->first()->message)->toContain('did not finish within 0 seconds');
});

test('a run without an ID cannot be polled', function () {
    $client = makeClient([US => ['start' => jsonResponse(201, ['data' => ['status' => 'RUNNING']])]]);

    expect($client->scrape([Source::WashingtonPost])->errors->first()->message)->toContain('missing run ID');
});

test('maxConcurrency 2 starts both runs before reading datasets', function () {
    $history = [];
    $client = makeClient([UK => [], US => []], $history, new Config(maxConcurrency: 2));

    $client->scrape([Source::BbcNews, Source::WashingtonPost]);

    $order = array_map(
        fn ($h) => $h['request']->getMethod(),
        $history,
    );
    expect($order)->toBe(['POST', 'POST', 'GET', 'GET']);
});

test('maxConcurrency 1 runs the collectors one after another', function () {
    $history = [];
    $client = makeClient([UK => [], US => []], $history, new Config(maxConcurrency: 1));

    $client->scrape([Source::BbcNews, Source::WashingtonPost]);

    $order = array_map(fn ($h) => $h['request']->getMethod(), $history);
    expect($order)->toBe(['POST', 'GET', 'POST', 'GET']);
});

test('setApifyKey is fluent and the key is sent as bearer token', function () {
    $history = [];
    $client = makeClient([US => []], $history, apiKey: null);

    expect($client->setApifyKey('my-key'))->toBe($client);

    $client->scrape([Source::WashingtonPost]);

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
        makeClient([US => []], $history, apiKey: null)->scrape([Source::WashingtonPost]);

        expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer env-token');
    });
});

test('APIFY_API_TOKEN wins over APIFY_TOKEN', function () {
    withEnv('primary', 'secondary', function () {
        $history = [];
        makeClient([US => []], $history, apiKey: null)->scrape([Source::WashingtonPost]);

        expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer primary');
    });
});

test('setApifyKey wins over the environment', function () {
    withEnv('primary', 'secondary', function () {
        $history = [];
        makeClient([US => []], $history, apiKey: 'explicit')->scrape([Source::WashingtonPost]);

        expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer explicit');
    });
});

test('without any key the first call throws before a request is sent', function () {
    withEnv(null, null, function () {
        $history = [];
        $client = makeClient([US => []], $history, apiKey: null);

        expect(fn () => $client->scrape([Source::WashingtonPost]))->toThrow(MissingApiKeyException::class)
            ->and($history)->toBe([]);
    });
});

test('searchQuery is sent to every collector run', function () {
    $history = [];
    $client = makeClient([UK => [], US => []], $history);

    $client->scrape([Source::BbcNews, Source::WashingtonPost], searchQuery: '  climate  ');

    $posts = array_values(array_filter($history, fn ($h) => $h['request']->getMethod() === 'POST'));

    expect($posts)->toHaveCount(2)
        ->and(sentBody($posts[0]['request'])['search_query'])->toBe('climate')
        ->and(sentBody($posts[1]['request'])['search_query'])->toBe('climate');
});

test('searchQuery is omitted when not set', function () {
    $history = [];
    makeClient([US => []], $history)->scrape([Source::WashingtonPost]);

    expect(sentBody($history[0]['request']))->not->toHaveKey('search_query');
});

test('an invalid searchQuery throws before any request', function (string $query) {
    $history = [];
    $client = makeClient([], $history);

    expect(fn () => $client->scrape([Source::WashingtonPost], searchQuery: $query))
        ->toThrow(InvalidInputException::class, 'searchQuery must be 1 to 100 characters')
        ->and($history)->toBe([]);
})->with(['empty' => [''], 'blank' => ['   '], 'too long' => [str_repeat('a', 101)]]);

test('a searchQuery of 100 characters is accepted', function () {
    $history = [];
    makeClient([US => []], $history)->scrape([Source::WashingtonPost], searchQuery: str_repeat('é', 100));

    expect(mb_strlen(sentBody($history[0]['request'])['search_query']))->toBe(100);
});

test('startDate and endDate are sent as YYYY-MM-DD', function () {
    $history = [];
    $client = makeClient([US => []], $history);

    $client->scrape([Source::WashingtonPost], startDate: '2026-01-05', endDate: new DateTimeImmutable('2026-02-10 13:45'));

    $body = sentBody($history[0]['request']);
    expect($body['start_date'])->toBe('2026-01-05')->and($body['end_date'])->toBe('2026-02-10');
});

test('dates are omitted when not set', function () {
    $history = [];
    makeClient([US => []], $history)->scrape([Source::WashingtonPost]);

    expect(sentBody($history[0]['request']))->not->toHaveKey('start_date')->not->toHaveKey('end_date');
});

test('invalid dates throw before any request', function (array $dates, string $message) {
    $history = [];
    $client = makeClient([], $history);

    expect(fn () => $client->scrape([Source::WashingtonPost], ...$dates))
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

    expect(fn () => $client->scrape([Source::WashingtonPost], startDate: $future))
        ->toThrow(InvalidInputException::class, 'startDate must not be in the future')
        ->and(fn () => $client->scrape([Source::WashingtonPost], endDate: $old))
        ->toThrow(InvalidInputException::class, 'endDate must not be older than 5 years');
});

test('today is a valid startDate and endDate', function () {
    $history = [];
    $today = gmdate('Y-m-d');

    makeClient([US => []], $history)->scrape([Source::WashingtonPost], startDate: $today, endDate: $today);

    expect(sentBody($history[0]['request'])['start_date'])->toBe($today);
});

test('limit is sent to every collector run', function () {
    $history = [];
    $client = makeClient([UK => [], US => []], $history);

    $client->scrape([Source::BbcNews, Source::WashingtonPost], limit: 25);

    $posts = array_values(array_filter($history, fn ($h) => $h['request']->getMethod() === 'POST'));

    expect(sentBody($posts[0]['request'])['limit'])->toBe(25)
        ->and(sentBody($posts[1]['request'])['limit'])->toBe(25);
});

test('limit 0 means unlimited and is sent as 0', function () {
    $history = [];
    makeClient([US => []], $history)->scrape([Source::WashingtonPost], limit: 0);

    expect(sentBody($history[0]['request']))->toHaveKey('limit', 0);
});

test('limit is omitted by default so the Actor default applies', function () {
    $history = [];
    makeClient([US => []], $history)->scrape([Source::WashingtonPost]);

    expect(sentBody($history[0]['request']))->not->toHaveKey('limit');
});

test('negative limit throws before any request', function (int $limit) {
    $history = [];
    $client = makeClient([], $history);

    expect(fn () => $client->scrape([Source::WashingtonPost], limit: $limit))
        ->toThrow(InvalidInputException::class, 'limit must not be negative')
        ->and($history)->toBe([]);
})->with([-1, PHP_INT_MIN]);

test('limit above the Actor maximum of 10000 throws before any request', function (int $limit) {
    $history = [];
    $client = makeClient([], $history);

    expect(fn () => $client->scrape([Source::WashingtonPost], limit: $limit))
        ->toThrow(InvalidInputException::class, 'limit must not be greater than 10000')
        ->and($history)->toBe([]);
})->with([10001, 50000, PHP_INT_MAX]);

test('limit of 10000 is accepted', function () {
    $history = [];
    makeClient([US => []], $history)->scrape([Source::WashingtonPost], limit: 10000);

    expect(sentBody($history[0]['request'])['limit'])->toBe(10000);
});

test('analysis flags are sent to every collector run when enabled', function (string $param, string $flag) {
    $history = [];
    $client = makeClient([UK => [], US => []], $history);

    $client->scrape([Source::BbcNews, Source::WashingtonPost], ...[$param => true]);

    $posts = array_values(array_filter($history, fn ($h) => $h['request']->getMethod() === 'POST'));

    expect(sentBody($posts[0]['request'])[$flag])->toBeTrue()
        ->and(sentBody($posts[1]['request'])[$flag])->toBeTrue();
})->with([
    'sentiment' => ['withSentiment', 'with_sentiment'],
    'tags' => ['withTags', 'with_tags'],
    'summary' => ['withSummary', 'with_summary'],
    '5w1h' => ['with5w1h', 'with_5w1h'],
    'language detection' => ['withLanguageDetection', 'with_language_detection'],
    'fix text' => ['withFixText', 'with_fix_text'],
]);

test('analysis flags are omitted by default', function () {
    $history = [];
    makeClient([US => []], $history)->scrape([Source::WashingtonPost]);

    expect(array_keys(sentBody($history[0]['request'])))->not->toContain(
        'with_sentiment',
        'with_tags',
        'with_summary',
        'with_5w1h',
        'with_language_detection',
        'with_fix_text',
    );
});

test('enriched articles expose the analysis results', function () {
    $client = makeClient([UK => ['items' => [loadFixture('article_enriched')]]]);

    $article = $client->scrape([Source::BbcNews], withSentiment: true, withTags: true, withSummary: true)
        ->entries->first();

    expect($article->hasEnrichment())->toBeTrue()
        ->and($article->sentimentResult()?->label)->toBe(SentimentLabel::Positive)
        ->and($article->tagsResult()?->tags)->toBe(['Politics', 'Economy'])
        ->and($article->summaryResult()?->text)->toBe('Short summary.');
});

test('all analysis add-ons can be combined and reach the article', function () {
    $history = [];
    $client = makeClient([UK => ['items' => [loadFixture('article_enriched')]]], $history);

    $article = $client->scrape(
        [Source::BbcNews],
        withSentiment: true,
        withTags: true,
        withSummary: true,
        with5w1h: true,
        withLanguageDetection: true,
        withFixText: true,
    )->entries->first();

    $body = sentBody($history[0]['request']);

    expect($body)->toMatchArray([
        'with_sentiment' => true,
        'with_tags' => true,
        'with_summary' => true,
        'with_5w1h' => true,
        'with_language_detection' => true,
        'with_fix_text' => true,
    ])->and($article->fiveW1hResult()?->who?->answer)->toBe('Example Ministry')
        ->and($article->detectedLanguageResult()?->language)->toBe('en')
        ->and($article->fixedTextResult()?->text)->toBe('Body text of the example article.');
});

/**
 * @param  list<Response|Throwable>  $responses
 * @param  list<float>  $sleeps
 */
function retryClient(array $responses, array &$sleeps, ?Config $config = null): Client
{
    $client = new Client(
        $config ?? new Config,
        new GuzzleHttp\Client(['handler' => HandlerStack::create(new MockHandler($responses))]),
        static function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        },
    );

    return $client->setApifyKey('test-token');
}

function connectFailure(): ConnectException
{
    return new ConnectException('down', new Request('GET', '/'));
}

function runBody(string $status = 'SUCCEEDED'): Response
{
    return jsonResponse(201, ['data' => ['id' => 'r1', 'status' => $status, 'defaultDatasetId' => 'd1']]);
}

test('a GET is retried after 5xx and connection failures with growing delays', function () {
    $sleeps = [];
    $client = retryClient([
        runBody(),
        new Response(503, [], '{}'),
        connectFailure(),
        new Response(200, [], json_encode([article('https://a.test/1')], JSON_THROW_ON_ERROR)),
    ], $sleeps);

    $result = $client->scrape([Source::WashingtonPost]);

    expect($result->entries)->toHaveCount(1)
        ->and($sleeps)->toHaveCount(2)
        ->and($sleeps[0])->toBeBetween(0.5, 1.0)
        ->and($sleeps[1])->toBeBetween(1.0, 2.0);
});

test('retrying stops after maxRetries', function () {
    $sleeps = [];
    $client = retryClient(
        [runBody(), new Response(500, [], '{}'), new Response(500, [], '{}'), new Response(500, [], '{}')],
        $sleeps,
        new Config(maxRetries: 2),
    );

    $result = $client->scrape([Source::WashingtonPost]);

    expect($result->errors->first()->message)->toContain('HTTP 500')->and($sleeps)->toHaveCount(2);
});

test('the run start is retried after 429 and a failed connection', function () {
    $sleeps = [];
    $client = retryClient([
        new Response(429, ['Retry-After' => '3'], '{}'),
        connectFailure(),
        runBody(),
        new Response(200, [], '[]'),
    ], $sleeps);

    $result = $client->scrape([Source::WashingtonPost]);

    expect($result->isComplete())->toBeTrue()
        ->and($sleeps)->toHaveCount(2)
        ->and($sleeps[0])->toBeGreaterThanOrEqual(3.0);
});

test('the run start is never retried after 5xx or a timeout', function (Response|Throwable $failure) {
    $sleeps = [];
    $result = retryClient([$failure, runBody(), new Response(200, [], '[]')], $sleeps)->scrape([Source::WashingtonPost]);

    expect($result->errors)->toHaveCount(1)->and($sleeps)->toBeEmpty();
})->with([
    '500' => fn () => new Response(500, [], '{}'),
    '503' => fn () => new Response(503, [], '{}'),
    'timeout' => fn () => new RequestException(
        'cURL error 28: timed out',
        new Request('POST', '/'),
    ),
]);

test('on Guzzle 8 the run start is retried after a connect timeout but not after a network timeout', function () {
    $connect = '\\GuzzleHttp\\Exception\\ConnectTimeoutException';
    $network = '\\GuzzleHttp\\Exception\\NetworkTimeoutException';
    $request = new Request('POST', '/');

    $sleeps = [];
    $result = retryClient(
        [new $connect('connect timed out', $request), runBody(), new Response(200, [], '[]')],
        $sleeps,
    )->scrape([Source::WashingtonPost]);

    expect($result->isComplete())->toBeTrue()->and($sleeps)->toHaveCount(1);

    $sleeps = [];
    $result = retryClient(
        [new $network('read timed out', $request), runBody(), new Response(200, [], '[]')],
        $sleeps,
    )->scrape([Source::WashingtonPost]);

    expect($result->errors)->toHaveCount(1)->and($sleeps)->toBeEmpty();
})->skip(
    ! class_exists('\\GuzzleHttp\\Exception\\ConnectTimeoutException'),
    'Guzzle 8 exception hierarchy only',
);

test('4xx other than 429 is not retried', function () {
    $sleeps = [];
    $result = retryClient([new Response(400, [], '{}')], $sleeps)->scrape([Source::WashingtonPost]);

    expect($result->errors)->toHaveCount(1)->and($sleeps)->toBeEmpty();
});

test('Retry-After is read as seconds or as HTTP date and a missing one falls back to backoff', function () {
    $sleeps = [];
    $date = gmdate('D, d M Y H:i:s \G\M\T', time() + 10);

    retryClient([
        new Response(429, ['Retry-After' => '7'], '{}'),
        new Response(429, ['Retry-After' => $date], '{}'),
        new Response(429, [], '{}'),
        runBody(),
        new Response(200, [], '[]'),
    ], $sleeps)->scrape([Source::WashingtonPost]);

    expect($sleeps)->toHaveCount(3)
        ->and($sleeps[0])->toBe(7.0)
        ->and($sleeps[1])->toBeBetween(8.0, 10.0)
        ->and($sleeps[2])->toBeBetween(2.0, 4.0);
});

test('a Retry-After above retryMaxDelay is not waited out', function () {
    $sleeps = [];
    $result = retryClient([new Response(429, ['Retry-After' => '120'], '{}')], $sleeps)->scrape([Source::WashingtonPost]);

    expect($sleeps)->toBeEmpty()->and($result->errors->first()->message)->toContain('retry after 120 seconds');
});

test('maxRetries 0 turns retrying off', function () {
    $sleeps = [];
    $result = retryClient([new Response(429, [], '{}')], $sleeps, new Config(maxRetries: 0))->scrape([Source::WashingtonPost]);

    expect($sleeps)->toBeEmpty()->and($result->errors)->toHaveCount(1);
});

test('the delay is capped at retryMaxDelay', function () {
    $sleeps = [];
    retryClient(
        [runBody(), new Response(500, [], '{}'), new Response(500, [], '{}'), new Response(200, [], '[]')],
        $sleeps,
        new Config(retryBaseDelay: 10.0, retryMaxDelay: 12.0),
    )->scrape([Source::WashingtonPost]);

    expect($sleeps[0])->toBeBetween(5.0, 10.0)->and($sleeps[1])->toBeBetween(6.0, 12.0);
});

function abortRequests(array $history): array
{
    return array_values(array_filter(
        $history,
        fn ($h) => str_ends_with($h['request']->getUri()->getPath(), '/abort'),
    ));
}

test('an expired deadline aborts every pending run once and says so', function () {
    $history = [];
    $client = makeClient(
        [US => ['statuses' => ['RUNNING']], UK => ['statuses' => ['RUNNING']]],
        $history,
        new Config(timeout: 0, maxConcurrency: 2),
    );

    $result = $client->scrape([Source::BbcNews, Source::WashingtonPost]);

    $aborts = abortRequests($history);
    $paths = array_map(fn ($h) => $h['request']->getUri()->getPath(), $aborts);
    sort($paths);

    expect($aborts)->toHaveCount(2)
        ->and($paths)->toBe(['/v2/actor-runs/run-'.UK.'/abort', '/v2/actor-runs/run-'.US.'/abort'])
        ->and($aborts[0]['request']->getMethod())->toBe('POST')
        ->and($aborts[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer test-token')
        ->and($aborts[0]['options']['timeout'])->toBe(10)
        ->and($result->errors->first()->message)->toBe('Actor run did not finish within 0 seconds and was aborted');
});

test('a failing abort still reports the original timeout without claiming an abort', function () {
    $history = [];
    $client = makeClient(
        [US => ['statuses' => ['RUNNING'], 'abort' => new Response(500, [], '{}')]],
        $history,
        new Config(timeout: 0),
    );

    $result = $client->scrape([Source::WashingtonPost]);

    expect(abortRequests($history))->toHaveCount(1)
        ->and($result->errors->first()->message)->toBe('Actor run did not finish within 0 seconds');
});

test('no abort after success or a failed run', function (string $status) {
    $history = [];
    $client = makeClient([US => ['statuses' => [$status]]], $history, new Config(timeout: 0));

    $client->scrape([Source::WashingtonPost]);

    expect(abortRequests($history))->toBeEmpty();
})->with(['SUCCEEDED', 'FAILED', 'ABORTED', 'TIMED-OUT']);

test('ApiException carries an optional run ID', function () {
    expect((new ApiException('x'))->runId)->toBeNull()
        ->and((new ApiException('x', 0, null, 'r1'))->runId)->toBe('r1');
});

test('abort posts to the run abort endpoint', function () {
    $history = [];
    $client = makeClient([US => []], $history);

    $client->abort('run-'.US);

    expect($history)->toHaveCount(1)
        ->and($history[0]['request']->getMethod())->toBe('POST')
        ->and($history[0]['request']->getUri()->getPath())->toBe('/v2/actor-runs/run-'.US.'/abort')
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer test-token');
});

test('abort throws on API errors', function () {
    $client = makeClient([US => ['abort' => new Response(404, [], '{}')]]);

    $client->abort('run-'.US);
})->throws(ApiException::class);

test('collector errors carry the run ID of failed and timed-out runs', function () {
    $failed = makeClient([US => ['statuses' => ['FAILED']]])->scrape([Source::WashingtonPost]);
    $timedOut = makeClient([US => ['statuses' => ['RUNNING']]], config: new Config(timeout: 0))
        ->scrape([Source::WashingtonPost]);

    expect($failed->errors->first()->runId)->toBe('run-'.US)
        ->and($timedOut->errors->first()->runId)->toBe('run-'.US);
});

test('a failed run adds the Actor statusMessage to the message and exposes it', function (string $status) {
    $result = makeClient([US => ['statuses' => [$status], 'statusMessage' => 'Blocked by the source']])
        ->scrape([Source::WashingtonPost]);
    $error = $result->errors->first();

    expect($error->message)->toBe("Actor run ended with status {$status}: Blocked by the source")
        ->and($error->statusMessage)->toBe('Blocked by the source')
        ->and($error->runId)->toBe('run-'.US)
        ->and($error->toArray()['statusMessage'])->toBe('Blocked by the source');
})->with(['FAILED', 'ABORTED', 'TIMED-OUT']);

test('a failed run without a usable statusMessage keeps the plain message', function (array $actor) {
    $error = makeClient([US => ['statuses' => ['FAILED']] + $actor])->scrape([Source::WashingtonPost])->errors->first();

    expect($error->message)->toBe('Actor run ended with status FAILED')
        ->and($error->statusMessage)->toBeNull()
        ->and($error->toArray())->not->toHaveKey('statusMessage');
})->with([
    'missing' => [[]],
    'empty string' => [['statusMessage' => '']],
    'array' => [['statusMessage' => ['a']]],
    'number' => [['statusMessage' => 5]],
    'null' => [['statusMessage' => null]],
]);

test('statusMessage is stripped of control characters and capped in the message only', function () {
    $raw = "Line one\nline\ttwo\x00".str_repeat('x', 3000);
    $error = makeClient([US => ['statuses' => ['FAILED'], 'statusMessage' => $raw]])
        ->scrape([Source::WashingtonPost])->errors->first();
    $reason = substr($error->message, strlen('Actor run ended with status FAILED: '));

    expect(mb_strlen($reason))->toBe(500)
        ->and($reason)->toStartWith('Line one line two x')
        ->and($reason)->not->toMatch('/[\x00-\x1f]/')
        ->and(mb_strlen((string) $error->statusMessage))->toBe(2000);
});

test('ApiException carries an optional statusMessage', function () {
    expect((new ApiException('x'))->statusMessage)->toBeNull()
        ->and((new ApiException('x', 0, null, 'r1', 'why'))->statusMessage)->toBe('why');
});

test('collector errors have no run ID when no run was started', function () {
    $result = makeClient([US => ['start' => jsonResponse(429, [])]])->scrape([Source::WashingtonPost]);

    expect($result->errors->first()->runId)->toBeNull()
        ->and($result->errors->first()->toArray())->not->toHaveKey('runId');
});

function limitResponse(): Response
{
    return jsonResponse(402, ['error' => [
        'type' => 'not-enough-usage-to-run-paid-actor',
        'message' => "Monthly usage hard limit exceeded.\nToken abc",
    ]]);
}

test('HTTP 402 on run start, polling and dataset becomes a SpendingLimitException', function (array $actor) {
    $history = [];

    try {
        makeClient([US => $actor], $history)->scrape([Source::WashingtonPost]);
        $this->fail('SpendingLimitException expected');
    } catch (SpendingLimitException $e) {
        expect($e)->toBeInstanceOf(ApiException::class)
            ->and($e->getCode())->toBe(402)
            ->and($e->errorType)->toBe('not-enough-usage-to-run-paid-actor')
            ->and($e->getMessage())->toContain('Monthly usage hard limit exceeded.')
            ->and($e->getMessage())->not->toMatch('/[\x00-\x1f]/');
    }
})->with([
    'run start' => [['start' => limitResponse()]],
    'polling' => [['statuses' => ['RUNNING', 'SUCCEEDED'], 'poll' => limitResponse()]],
    'dataset' => [['dataset' => limitResponse()]],
]);

test('an unparseable 402 body gives a null errorType and the abort call maps 402 too', function () {
    $sleeps = [];

    try {
        retryClient([new Response(402, [], 'not json')], $sleeps)->scrape([Source::WashingtonPost]);
        $this->fail('SpendingLimitException expected');
    } catch (SpendingLimitException $e) {
        expect($e->errorType)->toBeNull()->and($e->getCode())->toBe(402);
    }

    expect(fn () => retryClient([limitResponse()], $sleeps)->abort('r1'))
        ->toThrow(SpendingLimitException::class);
});

test('a 402 is never retried and the SpendingLimitException message is capped at 500 characters', function () {
    $sleeps = [];
    $long = jsonResponse(402, ['error' => ['type' => 'x', 'message' => str_repeat('y', 900)]]);

    try {
        retryClient([$long, runBody(), new Response(200, [], '[]')], $sleeps)->scrape([Source::WashingtonPost]);
        $this->fail('SpendingLimitException expected');
    } catch (SpendingLimitException $e) {
        expect(mb_strlen($e->getMessage()))->toBeLessThan(560)->and($sleeps)->toBeEmpty();
    }
});

test('scrape returns partial results when a 402 hits one collector and others succeeded', function () {
    $history = [];
    $client = makeClient([
        TW => ['items' => [article('https://a.test/tw')]],
        US => ['start' => limitResponse()],
    ], $history, new Config(maxRetries: 0, maxConcurrency: 1));

    $result = $client->scrape([Source::BbcNews, Source::WashingtonPost, Source::TaipeiTimes]);

    expect($result->entries->pluck('url')->all())->toBe(['https://a.test/tw'])
        ->and($result->errors->pluck('collector')->all())->toContain(Collector::Us)
        ->and($result->errors->first(fn ($e) => $e->collector === Collector::Us)->message)
        ->toContain('spending limit reached');
});

test('after a 402 no further collector run is started and the skipped ones are reported', function () {
    $history = [];
    $client = makeClient([
        TW => ['items' => [article('https://a.test/tw')]],
        US => ['start' => limitResponse()],
    ], $history, new Config(maxRetries: 0, maxConcurrency: 1));

    $result = $client->scrape([Source::BbcNews, Source::WashingtonPost, Source::TaipeiTimes]);

    expect(runStartQueries($history))->toHaveCount(2)
        ->and($result->errors)->toHaveCount(2)
        ->and($result->errors->last()->message)->toStartWith('Not started: ');
});

test('scrape throws SpendingLimitException when every collector hit the limit', function () {
    $history = [];
    $client = makeClient([
        TW => ['start' => limitResponse()],
        US => ['start' => limitResponse()],
    ], $history);

    expect(fn () => $client->scrape([Source::WashingtonPost, Source::TaipeiTimes]))
        ->toThrow(SpendingLimitException::class);
});

/**
 * @param  list<array{request: RequestInterface}>  $history
 * @return list<string>
 */
function runStartQueries(array $history): array
{
    $queries = [];

    foreach ($history as $entry) {
        $uri = $entry['request']->getUri();

        if ($entry['request']->getMethod() === 'POST' && str_ends_with($uri->getPath(), '/runs')) {
            $queries[] = $uri->getQuery();
        }
    }

    return $queries;
}

test('the run start sends the client timeout as the Apify run timeout by default', function () {
    $history = [];
    $client = makeClient([US => ['statuses' => ['RUNNING']]], $history, new Config(timeout: 1200, maxRetries: 0));

    $client->scrape(Source::NewYorkTimes);

    expect(runStartQueries($history))->toBe(['timeout=1200']);
});

test('Config::$runTimeout wins over the client timeout', function () {
    $history = [];
    $client = makeClient([US => []], $history, new Config(timeout: 1200, maxRetries: 0, runTimeout: 300));

    $client->scrape(Source::NewYorkTimes);

    expect(runStartQueries($history))->toBe(['timeout=300']);
});

test('the per-call runTimeout wins over the config', function () {
    $history = [];
    $client = makeClient([US => []], $history, new Config(timeout: 1200, maxRetries: 0, runTimeout: 300));

    $client->scrape(Source::NewYorkTimes, runTimeout: 45);

    expect(runStartQueries($history))->toBe(['timeout=45']);
});

test('runTimeout 0 sends no timeout, in the config and per call', function () {
    $history = [];
    makeClient([US => []], $history, new Config(maxRetries: 0, runTimeout: 0))->scrape(Source::NewYorkTimes);

    expect(runStartQueries($history))->toBe(['']);

    $history = [];
    makeClient([US => []], $history)->scrape(Source::NewYorkTimes, runTimeout: 0);

    expect(runStartQueries($history))->toBe(['']);
});

test('a per-call runTimeout of 0 beats a configured one', function () {
    $history = [];
    makeClient([US => []], $history, new Config(maxRetries: 0, runTimeout: 300))
        ->scrape(Source::NewYorkTimes, runTimeout: 0);

    expect(runStartQueries($history))->toBe(['']);
});

test('only the run start carries the timeout, not polling or dataset requests', function () {
    $history = [];
    $client = makeClient([US => ['statuses' => ['RUNNING']]], $history, new Config(timeout: 600, maxRetries: 0));

    $client->scrape(Source::NewYorkTimes);

    $others = array_filter($history, fn ($h) => $h['request']->getMethod() === 'GET');

    expect($others)->not->toBeEmpty();

    foreach ($others as $entry) {
        expect($entry['request']->getUri()->getQuery())->not->toContain('timeout=');
    }
});

test('every concurrent collector run carries the run timeout', function () {
    $history = [];
    $client = makeClient(
        [US => [], UK => [], TW => []],
        $history,
        new Config(timeout: 700, maxRetries: 0, maxConcurrency: 3),
    );

    $client->scrape([Source::BbcNews, Source::WashingtonPost, Source::TaipeiTimes], runTimeout: 120);

    expect(runStartQueries($history))->toBe(['timeout=120', 'timeout=120', 'timeout=120']);
});

test('a negative per-call runTimeout throws before any request', function () {
    $history = [];
    $client = makeClient([US => []], $history);

    expect(fn () => $client->scrape(Source::NewYorkTimes, runTimeout: -1))
        ->toThrow(InvalidInputException::class, 'runTimeout must be a positive number of seconds');
    expect($history)->toBeEmpty();
});

/**
 * A client over queued responses that records the requests; the sleeper records and, if asked, really waits.
 *
 * @param  list<Response|Throwable>  $responses
 * @param  array<int, array<string, mixed>>  $history
 * @param  list<float>  $sleeps
 */
function deadlineClient(array $responses, array &$history, array &$sleeps, Config $config, bool $wait = false): Client
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return (new Client(
        $config,
        new GuzzleHttp\Client(['handler' => $stack]),
        static function (float $seconds) use (&$sleeps, $wait): void {
            $sleeps[] = $seconds;

            if ($wait) {
                usleep((int) ($seconds * 1_000_000));
            }
        },
    ))->setApifyKey('test-token');
}

test('the run start is not repeated when the request may have reached the server', function (string $message) {
    $sleeps = [];
    $failure = new ConnectException($message, new Request('POST', '/'));
    $client = retryClient([$failure, runBody(), new Response(200, [], '[]')], $sleeps);
    $result = $client->scrape([Source::WashingtonPost]);

    expect($result->errors)->toHaveCount(1)->and($sleeps)->toBeEmpty();
})->with([
    'Guzzle 7 read timeout' => 'cURL error 28: Operation timed out after 90000 milliseconds with 0 bytes received',
    'empty reply' => 'cURL error 52: Empty reply from server',
    'timeout without a cURL code' => 'Connection timed out',
]);

test('the run start is retried when the request was provably never sent', function (string $message) {
    $sleeps = [];
    $failure = new ConnectException($message, new Request('POST', '/'));
    $client = retryClient([$failure, runBody(), new Response(200, [], '[]')], $sleeps);
    $result = $client->scrape([Source::WashingtonPost]);

    expect($result->isComplete())->toBeTrue()->and($sleeps)->toHaveCount(1);
})->with([
    'DNS' => 'cURL error 6: Could not resolve host: api.apify.com',
    'connect' => 'cURL error 7: Failed to connect to api.apify.com port 443',
    'TLS' => 'cURL error 35: TLS connect error',
]);

test('a retry whose wait would pass the overall timeout is skipped', function () {
    $history = [];
    $sleeps = [];
    $client = deadlineClient(
        [new Response(429, ['Retry-After' => '20'], '{}'), runBody()],
        $history,
        $sleeps,
        new Config(timeout: 10),
    );

    $result = $client->scrape([Source::WashingtonPost]);

    expect($result->errors->first()->message)->toContain('retry after 20 seconds')
        ->and($history)->toHaveCount(1)
        ->and($sleeps)->toBeEmpty();
});

test('the overall timeout bounds all retries together, cuts request timeouts and aborts the run', function () {
    $history = [];
    $sleeps = [];
    $limited = static fn () => new Response(429, ['Retry-After' => '1'], '{}');
    $client = deadlineClient(
        [runBody('RUNNING'), $limited(), $limited(), $limited(), runBody('ABORTING')],
        $history,
        $sleeps,
        new Config(timeout: 3, retryBaseDelay: 0.1),
        true,
    );

    $result = $client->scrape([Source::WashingtonPost]);

    expect($result->errors->first()->message)->toContain('rate limit reached')
        ->and($sleeps)->toBe([1.0, 1.0])
        ->and($history)->toHaveCount(5)
        ->and($history[1]['options']['timeout'])->toBe(8)
        ->and($history[3]['options']['timeout'])->toBe(6)
        ->and($history[4]['request']->getUri()->getPath())->toEndWith('/actor-runs/r1/abort');
});

test('request timeouts are cut to the time left, and reading the results keeps a minimum window', function () {
    $history = [];
    $sleeps = [];
    $client = deadlineClient(
        [runBody('RUNNING'), runBody(), new Response(200, [], '[]')],
        $history,
        $sleeps,
        new Config(timeout: 10),
    );

    $client->scrape([Source::WashingtonPost]);

    expect(array_map(static fn (array $h): mixed => $h['options']['timeout'], $history))->toBe([15, 15, 35]);
});

test('a 503 Retry-After is waited out while polling', function () {
    $sleeps = [];
    $result = retryClient([
        runBody('RUNNING'), new Response(503, ['Retry-After' => '5'], '{}'), runBody(), new Response(200, [], '[]'),
    ], $sleeps)->scrape([Source::WashingtonPost]);

    expect($result->isComplete())->toBeTrue()->and($sleeps)->toBe([5.0]);
});

test('a 408 is not retried, neither on the run start nor while polling', function (array $responses, int $requests) {
    $history = [];
    $sleeps = [];
    $result = deadlineClient($responses, $history, $sleeps, new Config)->scrape([Source::WashingtonPost]);

    expect($result->errors->first()->message)->toContain('HTTP 408')
        ->and($history)->toHaveCount($requests)
        ->and($sleeps)->toBeEmpty();
})->with([
    'run start' => [fn () => [new Response(408, [], '{}'), runBody()], 1],
    'poll' => [fn () => [runBody('RUNNING'), new Response(408, [], '{}'), runBody()], 2],
]);
