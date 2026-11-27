<?php

declare(strict_types=1);

use GlobalNewsScraper\Support\UrlKey;

test('URL variants of one page share a key', function (string $variant) {
    expect(UrlKey::of($variant))->toBe(UrlKey::of('https://a.test/news/story?id=7'));
})->with([
    'http' => 'http://a.test/news/story?id=7',
    'www' => 'https://www.a.test/news/story?id=7',
    'upper-case scheme and host' => 'HTTPS://WWW.A.TEST/news/story?id=7',
    'host with a trailing dot' => 'https://a.test./news/story?id=7',
    'default https port' => 'https://a.test:443/news/story?id=7',
    'default http port' => 'http://a.test:80/news/story?id=7',
    'trailing slash' => 'https://a.test/news/story/?id=7',
    'fragment' => 'https://a.test/news/story?id=7#comments',
    'utm parameters' => 'https://a.test/news/story?utm_source=rss&id=7&UTM_Medium=feed',
    'click ids' => 'https://a.test/news/story?fbclid=x&id=7&gclid=y&at_medium=RSS',
    'empty pairs' => 'https://a.test/news/story?&id=7&',
    'surrounding whitespace' => "  https://a.test/news/story?id=7\n",
]);

test('different pages keep different keys', function (string $first, string $second) {
    expect(UrlKey::of($first))->not->toBe(UrlKey::of($second));
})->with([
    'path' => ['https://a.test/one', 'https://a.test/two'],
    'path case' => ['https://a.test/Story', 'https://a.test/story'],
    'parameter value' => ['https://a.test/s?id=1', 'https://a.test/s?id=2'],
    'parameter value case' => ['https://a.test/s?id=Ab', 'https://a.test/s?id=ab'],
    'unknown parameter' => ['https://a.test/s?ref=1', 'https://a.test/s'],
    'repeated parameter order' => ['https://a.test/s?a=1&a=2', 'https://a.test/s?a=2&a=1'],
    'dot and underscore names' => ['https://a.test/s?a.b=1', 'https://a.test/s?a_b=1'],
    'subdomain' => ['https://m.a.test/s', 'https://a.test/s'],
    'port' => ['https://a.test:8443/s', 'https://a.test/s'],
    'hash route' => ['https://a.test/#/one', 'https://a.test/#/two'],
    'hashbang route' => ['https://a.test/#!one', 'https://a.test/#!two'],
    'encoded slash' => ['https://a.test/a%2Fb', 'https://a.test/a/b'],
    'other scheme' => ['ftp://a.test/s', 'https://a.test/s'],
]);

test('parameter order and escape case do not matter', function () {
    expect(UrlKey::of('https://a.test/%c3%a9?b=2&a=%2f'))->toBe(UrlKey::of('https://a.test/%C3%A9?a=%2F&b=2'));
});

test('the root with and without slash is one page', function () {
    expect(UrlKey::of('https://a.test'))->toBe(UrlKey::of('https://a.test/'));
});

test('anything but a full http URL is compared as it is', function (string $url) {
    expect(UrlKey::of($url))->toBe($url);
})->with(['', 'not a url', '/relative/path/', 'http:///broken', 'mailto:news@a.test']);
