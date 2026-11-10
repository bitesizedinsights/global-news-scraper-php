<?php

declare(strict_types=1);

use GlobalNewsScraper\Config;

test('config has sensible defaults', function () {
    $config = new Config;

    expect($config->baseUrl)->toBe('https://api.apify.com/v2')
        ->and($config->timeout)->toBe(900)
        ->and($config->requestTimeout)->toBe(90)
        ->and($config->maxConcurrency)->toBe(2);
});

test('config values can be overridden', function () {
    $config = new Config(baseUrl: 'https://example.com/v2', timeout: 60, requestTimeout: 10, maxConcurrency: 5);

    expect($config->baseUrl)->toBe('https://example.com/v2')
        ->and($config->timeout)->toBe(60)
        ->and($config->requestTimeout)->toBe(10)
        ->and($config->maxConcurrency)->toBe(5);
});
