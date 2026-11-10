<?php

declare(strict_types=1);

use GlobalNewsScraper\Client;
use GlobalNewsScraper\Config;
use GlobalNewsScraper\Tests\Support\FakeApify;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * @return array<string, mixed>
 */
function loadFixture(string $name): array
{
    $json = file_get_contents(__DIR__."/fixtures/{$name}.json");
    assert(is_string($json));

    /** @var array<string, mixed> */
    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, array<string, mixed>>  $actors  See FakeApify.
 * @param  array<int, array<string, mixed>>  $history
 */
function makeClient(
    array $actors,
    array &$history = [],
    ?Config $config = null,
    ?string $apiKey = 'test-token',
): Client {
    $stack = HandlerStack::create(new FakeApify($actors));
    $stack->push(Middleware::history($history));

    $client = new Client($config, new HttpClient(['handler' => $stack]));

    return $apiKey === null ? $client : $client->setApifyKey($apiKey);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function article(string $url, array $overrides = []): array
{
    return array_replace(loadFixture('article_full'), ['url' => $url], $overrides);
}

/**
 * @param  array<mixed>  $body
 * @param  array<string, string>  $headers
 */
function jsonResponse(int $status, array $body, array $headers = []): Response
{
    return new Response($status, $headers, json_encode($body, JSON_THROW_ON_ERROR));
}

/** @return array<string, mixed> */
function sentBody(RequestInterface $request): array
{
    /** @var array<string, mixed> */
    return json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
}
