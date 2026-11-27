<?php

declare(strict_types=1);

use GlobalNewsScraper\Client;
use GlobalNewsScraper\Config;
use GlobalNewsScraper\Exception\RateLimitException;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

test('abort() does not wait for a retry past Config::timeout and caps its request timeout', function () {
    $history = [];
    $sleeps = [];
    $stack = HandlerStack::create(new MockHandler([new Response(429, ['Retry-After' => '20']), new Response(200)]));
    $stack->push(Middleware::history($history));
    $client = (new Client(
        new Config(timeout: 10),
        new HttpClient(['handler' => $stack]),
        static function (float $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        },
    ))->setApifyKey('test-token');

    expect(fn () => $client->abort('run1'))->toThrow(RateLimitException::class)
        ->and($history)->toHaveCount(1)
        ->and($sleeps)->toBe([])
        ->and($history[0]['options']['timeout'])->toBeLessThanOrEqual(15);
});
