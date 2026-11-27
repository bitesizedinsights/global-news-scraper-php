<?php

declare(strict_types=1);

use GlobalNewsScraper\Client;
use GlobalNewsScraper\Collector;
use GlobalNewsScraper\Config;
use GlobalNewsScraper\DTO\Run;
use GlobalNewsScraper\DTO\RunBatch;
use GlobalNewsScraper\Exception\ApiException;
use GlobalNewsScraper\Exception\InvalidInputException;
use GlobalNewsScraper\Exception\MissingApiKeyException;
use GlobalNewsScraper\Exception\RateLimitException;
use GlobalNewsScraper\Exception\SpendingLimitException;
use GlobalNewsScraper\RunStatus;
use GlobalNewsScraper\Source;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

const A_US = 'bite-sized-insights~us-news-scraper';
const A_UK = 'bite-sized-insights~uk-news-scraper';
const A_TW = 'bite-sized-insights~taiwan-english-news-scraper';

/** Planned in the order tw-en, us, uk. */
const THREE = [Source::BbcNews, Source::WashingtonPost, Source::TaipeiTimes];

/**
 * @param  array<mixed>  $queue
 * @param  array<int, array<string, mixed>>  $history
 */
function queueClient(array $queue, array &$history = [], ?Config $config = null): Client
{
    $stack = HandlerStack::create(new MockHandler($queue));
    $stack->push(Middleware::history($history));

    $http = new HttpClient(['handler' => $stack]);

    return (new Client($config ?? new Config(maxRetries: 0), $http, static function (): void {}))
        ->setApifyKey('test-token');
}

/** @param  array<string, mixed>  $data */
function runResponse(array $data = []): Response
{
    return jsonResponse(201, ['data' => $data + ['id' => 'abc123', 'status' => 'READY', 'defaultDatasetId' => 'ds1']]);
}

function usageLimit(): Response
{
    return jsonResponse(402, ['error' => ['type' => 'not-enough-usage', 'message' => 'Monthly usage exceeded']]);
}

/**
 * @param  array<int, array<string, mixed>>  $history
 * @return list<string> `METHOD path` of every request.
 */
function requestLines(array $history): array
{
    return array_values(array_map(
        static fn (array $h): string => $h['request']->getMethod().' '.$h['request']->getUri()->getPath(),
        $history,
    ));
}

test('start starts every planned run and returns their handles without polling', function () {
    $history = [];
    $client = makeClient([
        US => ['statuses' => ['READY'], 'statusMessage' => 'Starting'],
        UK => ['statuses' => ['RUNNING']],
        TW => [],
    ], $history);

    $batch = $client->start(THREE);

    expect($batch->hasErrors())->toBeFalse()
        ->and($batch->runIds())->toBe(['run-'.A_TW, 'run-'.A_US, 'run-'.A_UK])
        ->and($batch->runs->pluck('collector')->all())->toBe([Collector::TwEn, Collector::Us, Collector::Uk])
        ->and($batch->runs[1]->status)->toBe(RunStatus::Ready)
        ->and($batch->runs[1]->defaultDatasetId)->toBe('ds-'.A_US)
        ->and($batch->runs[1]->statusMessage)->toBe('Starting')
        ->and($batch->isFinished())->toBeFalse()
        ->and($batch->isSuccessful())->toBeFalse()
        ->and(requestLines($history))->toBe([
            'POST /v2/acts/'.A_TW.'/runs', 'POST /v2/acts/'.A_US.'/runs', 'POST /v2/acts/'.A_UK.'/runs',
        ]);
});

test('start sends the same run start requests as scrape', function () {
    $options = [
        'searchQuery' => 'chips', 'startDate' => '2026-01-01', 'endDate' => '2026-02-01', 'limit' => 5,
        'withSentiment' => true, 'withTags' => true, 'withSummary' => true, 'with5w1h' => true,
        'withLanguageDetection' => true, 'withFixText' => true, 'runTimeout' => 120,
    ];
    $starts = function (array $history): array {
        $posts = array_filter($history, fn ($h) => $h['request']->getMethod() === 'POST');

        return array_values(array_map(
            fn ($h) => [(string) $h['request']->getUri(), sentBody($h['request'])],
            $posts,
        ));
    };
    $scrapeHistory = [];
    makeClient([US => [], UK => [], TW => []], $scrapeHistory)->scrape(THREE, ...$options);
    $startHistory = [];
    makeClient([US => [], UK => [], TW => []], $startHistory)->start(THREE, ...$options);

    expect($starts($startHistory))->toBe($starts($scrapeHistory))
        ->and($starts($startHistory)[0][0])->toEndWith('?timeout=120');
});

test('a failed start keeps the handles of the other runs and is not repeated', function (int $status) {
    $history = [];
    $client = makeClient(
        [US => ['start' => new Response($status, [], '{}')], UK => [], TW => []],
        $history,
        new Config(maxRetries: 3),
    );

    $batch = $client->start(THREE);

    expect($batch->runIds())->toBe(['run-'.A_TW, 'run-'.A_UK])
        ->and($batch->errors)->toHaveCount(1)
        ->and($batch->errors[0]->collector)->toBe(Collector::Us)
        ->and($batch->errors[0]->message)->toBe("Apify API request failed (HTTP {$status})")
        ->and($batch->errors[0]->runId)->toBeNull()
        ->and($batch->isSuccessful())->toBeFalse()
        ->and($history)->toHaveCount(3);
})->with([400, 401, 408, 500, 503]);

test('a start that timed out after it was sent is not repeated', function () {
    $history = [];
    $client = queueClient(
        [new ConnectException('cURL error 28: Operation timed out', new Request('POST', '/'))],
        $history,
        new Config(maxRetries: 3),
    );

    $batch = $client->start(Source::WashingtonPost);

    expect($batch->runs)->toBeEmpty()
        ->and($batch->errors[0]->message)->toBe('Apify API request failed: timed out (ConnectException)')
        ->and($history)->toHaveCount(1);
});

test('a rate limited start is repeated and yields one handle', function () {
    $history = [];
    $client = queueClient([new Response(429, [], '{}'), runResponse()], $history, new Config(maxRetries: 1));

    expect($client->start(Source::WashingtonPost)->runIds())->toBe(['abc123'])
        ->and($history)->toHaveCount(2);
});

test('an unexpected exception during one start does not lose the other handles', function () {
    $client = queueClient(
        [runResponse(), new RuntimeException('boom https://secret.test')],
        config: new Config(maxRetries: 0, maxConcurrency: 1),
    );

    $batch = $client->start([Source::WashingtonPost, Source::TaipeiTimes]);

    expect($batch->runIds())->toBe(['abc123'])
        ->and($batch->errors[0]->collector)->toBe(Collector::Us)
        ->and($batch->errors[0]->message)->toBe('Apify API request failed: transport error (RuntimeException)');
});

test('an unusable run ID in a start answer is reported, not used', function (mixed $id, ?string $reported) {
    $batch = queueClient([runResponse(['id' => $id])])->start(Source::WashingtonPost);

    expect($batch->runs)->toBeEmpty()
        ->and($batch->errors[0]->message)->toBe('Invalid API response: missing or invalid run ID')
        ->and($batch->errors[0]->runId)->toBe($reported);
})->with([[null, null], ['', null], [12, null], ['../../acts', '../../acts']]);

test('a started run keeps its handle when the status is unknown', function () {
    $batch = queueClient([runResponse(['status' => 'SOMETHING-NEW'])])->start(Source::WashingtonPost);

    expect($batch->runIds())->toBe(['abc123'])
        ->and($batch->runs[0]->status)->toBeNull()
        ->and($batch->isFinished())->toBeFalse();
});

test('after a spending limit no further run is started and the started handles are returned', function () {
    $history = [];
    $client = queueClient([runResponse(), usageLimit()], $history, new Config(maxRetries: 3, maxConcurrency: 1));

    $batch = $client->start(THREE);

    expect($batch->runIds())->toBe(['abc123'])
        ->and($batch->errors->map(fn ($e) => $e->collector)->all())->toBe([Collector::Us, Collector::Uk])
        ->and($batch->errors[0]->message)->toBe('Apify spending limit reached (HTTP 402): Monthly usage exceeded')
        ->and($batch->errors[1]->message)->toStartWith('Not started: Apify spending limit reached')
        ->and($history)->toHaveCount(2);
});

test('a run started beside the one that hit the spending limit is not lost', function () {
    $history = [];
    $client = queueClient([usageLimit(), runResponse()], $history, new Config(maxRetries: 0, maxConcurrency: 2));

    $batch = $client->start(THREE);

    expect($batch->runIds())->toBe(['abc123'])
        ->and($batch->errors)->toHaveCount(2);
});

test('start throws the spending limit when not a single run was started', function () {
    $history = [];
    $client = queueClient([usageLimit()], $history, new Config(maxRetries: 3, maxConcurrency: 1));

    expect(fn () => $client->start(THREE))->toThrow(SpendingLimitException::class)
        ->and($history)->toHaveCount(1);
});

test('start sends at most maxConcurrency requests at the same time', function (int $concurrency) {
    $open = 0;
    $peak = 0;
    $count = 0;
    $handler = function (RequestInterface $request) use (&$open, &$peak, &$count): PromiseInterface {
        $peak = max($peak, ++$open);
        $id = 'run'.++$count;
        $promise = new Promise(function () use (&$promise, &$open, $id): void {
            $open--;
            $promise->resolve(runResponse(['id' => $id]));
        });

        return $promise;
    };
    $client = (new Client(
        new Config(maxConcurrency: $concurrency),
        new HttpClient(['handler' => HandlerStack::create($handler)]),
    ))->setApifyKey('t');

    expect($client->start(THREE)->runs)->toHaveCount(3)->and($peak)->toBe($concurrency);
})->with([1, 2, 3]);

test('start validates the input before sending anything', function () {
    $history = [];
    $client = makeClient([US => []], $history);

    expect(fn () => $client->start([]))->toThrow(InvalidInputException::class)
        ->and(fn () => $client->start(Source::WashingtonPost, limit: -1))->toThrow(InvalidInputException::class)
        ->and(fn () => $client->start(Source::WashingtonPost, runTimeout: -1))->toThrow(InvalidInputException::class)
        ->and($history)->toBeEmpty();
});

test('start without an API key throws before any request', function () {
    putenv('APIFY_API_TOKEN');
    putenv('APIFY_TOKEN');
    $history = [];

    expect(fn () => makeClient([US => []], $history, apiKey: null)->start(Source::WashingtonPost))
        ->toThrow(MissingApiKeyException::class)
        ->and($history)->toBeEmpty();
});

test('a batch survives a round trip through JSON', function () {
    $batch = makeClient([
        US => ['statuses' => ['RUNNING'], 'statusMessage' => 'Working'],
        UK => ['start' => new Response(500, [], '{}')],
    ])->start([Source::WashingtonPost, Source::BbcNews]);

    $restored = RunBatch::fromArray(json_decode(json_encode($batch, JSON_THROW_ON_ERROR), true));

    expect($restored->toArray())->toBe($batch->toArray())
        ->and($restored->runs[0])->toEqual($batch->runs[0])
        ->and($restored->errors[0])->toEqual($batch->errors[0]);
});

test('a stored run with an unknown collector or status is rejected', function (array $override) {
    Run::fromArray($override + ['collector' => 'us', 'id' => 'abc123']);
})->with([[['collector' => 'nope']], [['status' => 'nope']]])->throws(InvalidArgumentException::class);

test('run statuses know whether they are final', function () {
    $final = array_filter(RunStatus::cases(), fn (RunStatus $s) => $s->isFinished());
    $failures = array_filter(RunStatus::cases(), fn (RunStatus $s) => $s->isFailure());

    expect(array_column($final, 'value'))->toBe(['SUCCEEDED', 'FAILED', 'ABORTED', 'TIMED-OUT'])
        ->and(array_column($failures, 'value'))->toBe(['FAILED', 'ABORTED', 'TIMED-OUT']);
});

test('getRun looks a handle up with one request and does not wait', function () {
    $history = [];
    $client = makeClient([US => ['statuses' => ['RUNNING']]], $history);

    $run = $client->getRun(new Run(Collector::Us, 'run-'.A_US));

    expect($run->status)->toBe(RunStatus::Running)
        ->and($run->collector)->toBe(Collector::Us)
        ->and(requestLines($history))->toBe(['GET /v2/actor-runs/run-'.A_US])
        ->and($history[0]['request']->getUri()->getQuery())->toBe('');
});

test('getRun identifies the collector of a bare run ID through its Actor, once per Actor', function () {
    $history = [];
    $client = makeClient([US => []], $history);

    expect($client->getRun('run-'.A_US)->collector)->toBe(Collector::Us)
        ->and($client->getRun('run-'.A_US)->collector)->toBe(Collector::Us)
        ->and(requestLines($history))->toBe([
            'GET /v2/actor-runs/run-'.A_US, 'GET /v2/acts/act-'.A_US, 'GET /v2/actor-runs/run-'.A_US,
        ]);
});

test('getRun refuses a run of an unknown Actor', function (mixed $actor) {
    $client = queueClient([runResponse(['actId' => 'act9']), jsonResponse(200, ['data' => $actor])]);

    expect(fn () => $client->getRun('abc123'))
        ->toThrow(ApiException::class, 'Run abc123 does not belong to a collector Actor of this library');
})->with([
    [['username' => 'someone', 'name' => 'us-news-scraper']],
    [['username' => 'bite-sized-insights', 'name' => 'transcription']],
    [['name' => 'us-news-scraper']],
    [null],
]);

test('getRun refuses a run without a usable Actor ID', function (mixed $actId) {
    $history = [];
    $client = queueClient([runResponse(['actId' => $actId])], $history);

    expect(fn () => $client->getRun('abc123'))
        ->toThrow(ApiException::class, 'Invalid API response: missing or invalid Actor ID')
        ->and($history)->toHaveCount(1);
})->with([null, '', '../users/me', 7]);

test('an unreadable Actor is reported with the run ID', function () {
    $client = queueClient([runResponse(['actId' => 'act9']), new Response(404, [], '{}')]);

    try {
        $client->getRun('abc123');
        $this->fail('No exception');
    } catch (ApiException $e) {
        expect($e->getCode())->toBe(404)->and($e->runId)->toBe('abc123');
    }
});

test('run IDs that could change the request path are refused before any request', function (string $id) {
    $history = [];
    $client = queueClient([], $history);

    expect(fn () => $client->getRun($id))->toThrow(InvalidInputException::class, 'Invalid run ID')
        ->and(fn () => $client->getRun(new Run(Collector::Us, $id)))->toThrow(InvalidInputException::class)
        ->and(fn () => $client->abort($id))->toThrow(InvalidInputException::class)
        ->and($history)->toBeEmpty();
})->with(['', '.', '..', '../acts', 'a/b', 'a?token=x', 'a#b', 'a%2Fb', 'a b', "abc\n", str_repeat('a', 65)]);

test('getRun maps API errors', function (Response $response, string $class, int $code) {
    try {
        queueClient([$response])->getRun('abc123');
        $this->fail('No exception');
    } catch (ApiException $e) {
        expect($e)->toBeInstanceOf($class)->and($e->getCode())->toBe($code);
    }
})->with([
    'unknown run' => [new Response(404, [], '{}'), ApiException::class, 404],
    'bad token' => [new Response(401, [], '{}'), ApiException::class, 401],
    'spending limit' => [new Response(402, [], '{}'), SpendingLimitException::class, 402],
    'rate limit' => [new Response(429, [], '{}'), RateLimitException::class, 0],
    'malformed JSON' => [new Response(200, [], 'nope'), ApiException::class, 0],
    'no run data' => [new Response(200, [], '{}'), ApiException::class, 0],
    'unknown status' => [new Response(200, [], '{"data":{"status":"NEW"}}'), ApiException::class, 0],
]);

test('errors about a run carry its ID', function (Response $response) {
    try {
        queueClient([$response])->getRun('abc123');
        $this->fail('No exception');
    } catch (ApiException $e) {
        expect($e->runId)->toBe('abc123');
    }
})->with([
    'unknown run' => [new Response(404, [], '{}')],
    'spending limit' => [new Response(402, [], '{}')],
    'malformed JSON' => [new Response(200, [], 'nope')],
    'unknown status' => [new Response(200, [], '{"data":{"status":"NEW"}}')],
]);

test('getRun retries a server error', function () {
    $history = [];
    $client = queueClient([new Response(502), runResponse()], $history, new Config(maxRetries: 1));

    expect($client->getRun(new Run(Collector::Us, 'abc123'))->status)->toBe(RunStatus::Ready)
        ->and($history)->toHaveCount(2);
});

test('abort accepts a run handle', function () {
    $history = [];
    makeClient([US => []], $history)->abort(new Run(Collector::Us, 'run-'.A_US));

    expect(requestLines($history))->toBe(['POST /v2/actor-runs/run-'.A_US.'/abort']);
});
