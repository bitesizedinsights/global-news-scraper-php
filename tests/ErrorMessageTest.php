<?php

declare(strict_types=1);

use GlobalNewsScraper\Client;
use GlobalNewsScraper\Config;
use GlobalNewsScraper\Exception\ApiException;
use GlobalNewsScraper\Exception\RateLimitException;
use GlobalNewsScraper\Exception\SpendingLimitException;
use GlobalNewsScraper\Source;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

function errClient(array $responses, ?Config $config = null): Client
{
    $client = new Client(
        $config ?? new Config(maxRetries: 0),
        new GuzzleHttp\Client(['handler' => HandlerStack::create(new MockHandler($responses))]),
        static function (float $seconds): void {},
    );

    return $client->setApifyKey('test-token');
}

function abortError(array $responses, ?Config $config = null): ApiException
{
    try {
        errClient($responses, $config)->abort('r1');
    } catch (ApiException $e) {
        return $e;
    }

    throw new RuntimeException('ApiException expected');
}

function expectClean(string $text): void
{
    expect($text)->not->toContain('http')
        ->and($text)->not->toContain('?token')
        ->and($text)->not->toContain('secret')
        ->and($text)->not->toContain('api.apify.com')
        ->and($text)->not->toContain('test-token');
}

test('an HTTP error message has status, type and message but no URL or token', function () {
    $body = json_encode(['error' => ['type' => 'run-failed', 'message' => 'Boom happened']]);
    $e = abortError([new Response(500, [], $body)]);

    expect($e->getMessage())->toBe('Apify API request failed (HTTP 500): run-failed: Boom happened')
        ->and($e->getCode())->toBe(500)
        ->and($e->responseBody)->toBe($body);
    expectClean($e->getMessage());
});

test('the raw body stays out of the message but is capped and cleaned in responseBody', function () {
    $raw = "https://api.apify.com/v2/x?token=secret\x00\n".str_repeat('a', 3000);
    $e = abortError([new Response(500, [], $raw)]);

    expect($e->getMessage())->toBe('Apify API request failed (HTTP 500)')
        ->and(mb_strlen((string) $e->responseBody))->toBeBetween(1990, 2000)
        ->and($e->responseBody)->toContain('?token=secret')
        ->and($e->responseBody)->not->toMatch('/[\x00-\x1f]/');
    expectClean($e->getMessage());
});

test('unparseable and empty bodies fall back to the generic text', function (string $body, ?string $expected) {
    $e = abortError([new Response(502, [], $body)]);

    expect($e->getMessage())->toBe('Apify API request failed (HTTP 502)')
        ->and($e->responseBody)->toBe($expected);
})->with([
    'html' => ['<html>bad gateway</html>', '<html>bad gateway</html>'],
    'empty' => ['', null],
    'json without error' => ['{"x":1}', '{"x":1}'],
]);

test('message parts are capped at 500 characters', function () {
    $body = json_encode(['error' => ['type' => 't', 'message' => str_repeat('y', 900)]]);
    $e = abortError([new Response(500, [], $body)]);

    expect(mb_strlen($e->getMessage()))->toBeLessThan(560);
});

test('transport errors name the class and never the URL', function (Throwable $e, string $text, string $class) {
    $thrown = abortError([$e]);

    expect($thrown->getMessage())->toBe("Apify API request failed: {$text} ({$class})")
        ->and($thrown->responseBody)->toBeNull();
    expectClean($thrown->getMessage());
})->with(function () {
    $request = new Request('GET', 'https://api.apify.com/v2/x?token=secret');

    return [
        'connect' => [new ConnectException('cURL error 7 for https://api.apify.com/v2/x?token=secret', $request),
            'connection error', 'ConnectException'],
        'timeout' => [new ConnectException('cURL error 28: Operation timed out https://x?token=secret', $request),
            'timed out', 'ConnectException'],
        'request' => [new RequestException('boom https://x?token=secret', $request), 'transport error',
            'RequestException'],
    ];
});

test('rate limit and spending limit messages stay meaningful and clean', function () {
    $rate = abortError([new Response(429, ['Retry-After' => '7'], '{"error":{"message":"https://x?token=secret"}}')]);
    $spend = abortError([new Response(402, [], json_encode([
        'error' => ['type' => 'not-enough-usage', 'message' => 'Monthly usage hard limit exceeded.'],
    ]))]);

    expect($rate)->toBeInstanceOf(RateLimitException::class)
        ->and($rate->getMessage())->toBe('Apify API rate limit reached (retry after 7 seconds)')
        ->and($rate->retryAfter)->toBe(7)
        ->and($rate->responseBody)->toContain('token=secret')
        ->and($spend)->toBeInstanceOf(SpendingLimitException::class)
        ->and($spend->getMessage())->toBe('Apify spending limit reached (HTTP 402): Monthly usage hard limit exceeded.')
        ->and($spend->errorType)->toBe('not-enough-usage')
        ->and($spend->responseBody)->toContain('not-enough-usage');
    expectClean($rate->getMessage());
});

test('retry exhaustion and malformed responses stay clean', function () {
    $bad = '{"error":{"type":"x","message":"see https://a.test/?token=secret"}}';
    $e = abortError([new Response(500, [], $bad), new Response(500, [], $bad)], new Config(maxRetries: 1));
    $malformed = errClient([new Response(200, [], 'not json ?token=secret')])->scrape([Source::Reuters]);

    expect($e->getMessage())->toStartWith('Apify API request failed (HTTP 500): x: see ');
    expect($malformed->errors->first()->message)->toBe('Invalid API response: malformed JSON');
});

test('collector errors stay readable and carry no URL', function () {
    $body = json_encode(['error' => ['type' => 'internal-error', 'message' => 'Try again later']]);
    $result = errClient([new Response(503, [], $body)])->scrape([Source::Reuters]);

    expect($result->errors->first()->message)->toBe('Apify API request failed (HTTP 503): internal-error: Try again later');

    $result = errClient([new ConnectException('down https://x?token=secret', new Request('POST', '/'))])
        ->scrape([Source::Reuters]);

    expect($result->errors->first()->message)->toBe('Apify API request failed: connection error (ConnectException)');
});

test('responseBody is an optional last constructor parameter', function () {
    $e = new ApiException('m', 1, null, 'run', 'status', 'body');

    expect($e->responseBody)->toBe('body')->and((new ApiException('m'))->responseBody)->toBeNull();
});
