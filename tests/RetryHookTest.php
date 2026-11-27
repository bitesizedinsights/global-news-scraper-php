<?php

declare(strict_types=1);

use GlobalNewsScraper\Client;
use GlobalNewsScraper\Config;
use GlobalNewsScraper\DTO\RetryEvent;
use GlobalNewsScraper\Exception\ApiException;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * @param  list<Response|Throwable>  $responses
 * @param  (Closure(RetryEvent): void)|null  $onRetry
 * @param  array<int, array<string, mixed>>  $history
 */
function hookClient(array $responses, ?Closure $onRetry, array &$history = [], ?Config $config = null): Client
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));

    return (new Client(
        $config ?? new Config,
        new HttpClient(['handler' => $stack]),
        static function (float $seconds): void {},
        $onRetry,
    ))->setApifyKey('secret-token');
}

function abortedRun(): Response
{
    return new Response(200, [], '{"data":{"id":"run1","status":"ABORTED"}}');
}

function unsentRequest(): ConnectException
{
    $url = 'https://user:secret-password@proxy.example.com/v2/actor-runs/run1/abort?token=secret-query';

    return new ConnectException("cURL error 7: Failed to connect for {$url}", new Request('POST', $url));
}

test('every retry is reported to the onRetry hook without secrets or IDs', function () {
    $events = [];
    $client = hookClient(
        [new Response(429, ['Retry-After' => '2']), unsentRequest(), abortedRun()],
        static function (RetryEvent $event) use (&$events): void {
            $events[] = $event;
        },
    );

    $client->abort('run1');

    expect($events)->toHaveCount(2)
        ->and($events[0]->method)->toBe('POST')
        ->and($events[0]->path)->toBe('/actor-runs/{id}/abort')
        ->and($events[0]->retry)->toBe(1)
        ->and($events[0]->maxRetries)->toBe(3)
        ->and($events[0]->delay)->toBe(2.0)
        ->and($events[0]->status)->toBe(429)
        ->and($events[0]->curlError)->toBeNull()
        ->and($events[0]->message())->toBe('Retry 1/3 of POST /actor-runs/{id}/abort in 2.0 s after HTTP 429')
        ->and($events[1]->retry)->toBe(2)
        ->and($events[1]->status)->toBeNull()
        ->and($events[1]->curlError)->toBe(7)
        ->and($events[1]->message())->toEndWith('after cURL error 7')
        ->and(json_encode($events).$events[1]->message())->not->toContain('secret')->not->toContain('run1');
});

test('an exception thrown by the onRetry hook is ignored', function () {
    $history = [];
    $client = hookClient(
        [new Response(429), abortedRun()],
        static function (): void {
            throw new RuntimeException('logger down');
        },
        $history,
    );

    $client->abort('run1');

    expect($history)->toHaveCount(2);
});

test('the onRetry hook is not called when nothing is retried', function (Response $response, Config $config) {
    $events = [];
    $client = hookClient(
        [$response, abortedRun()],
        static function (RetryEvent $event) use (&$events): void {
            $events[] = $event;
        },
        config: $config,
    );

    expect(fn () => $client->abort('run1'))->toThrow(ApiException::class)
        ->and($events)->toBe([]);
})->with([
    '5xx on a POST' => [new Response(500), new Config],
    'retrying off' => [new Response(429), new Config(maxRetries: 0)],
    'wait past the timeout' => [new Response(429, ['Retry-After' => '20']), new Config(timeout: 10)],
]);
