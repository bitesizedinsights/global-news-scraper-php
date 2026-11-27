<?php

declare(strict_types=1);

use GlobalNewsScraper\DTO\Article;

function publishedAt(?string $date, ?DateTimeZone $zone = null): ?string
{
    $article = new Article(url: 'https://a.test/1', collection: 'us', datePublish: $date);

    return $article->publishedAt($zone)?->format('Y-m-d\TH:i:s.uP');
}

test('ISO 8601 dates are parsed and keep their offset', function (string $value, string $expected) {
    expect(publishedAt($value, new DateTimeZone('Asia/Tokyo')))->toBe($expected);
})->with([
    'offset' => ['2026-02-15T10:30:00+05:30', '2026-02-15T10:30:00.000000+05:30'],
    'negative offset' => ['2026-02-15T10:30:00-08:00', '2026-02-15T10:30:00.000000-08:00'],
    'zulu' => ['2026-02-15T10:30:00Z', '2026-02-15T10:30:00.000000+00:00'],
    'microseconds' => ['2026-02-15T10:30:00.123456+00:00', '2026-02-15T10:30:00.123456+00:00'],
    'nanoseconds are cut' => ['2026-02-15T10:30:00.1234567+00:00', '2026-02-15T10:30:00.123456+00:00'],
    'compact offset' => ['2026-02-15 10:30+0900', '2026-02-15T10:30:00.000000+09:00'],
    'hour offset' => ['2026-02-15T10:30:00+14', '2026-02-15T10:30:00.000000+14:00'],
]);

test('values without an offset are wall-clock time in UTC or the assumed zone', function () {
    expect(publishedAt('2026-02-15T10:30:00'))->toBe('2026-02-15T10:30:00.000000+00:00')
        ->and(publishedAt('2026-02-15T10:30:00', new DateTimeZone('Asia/Tokyo')))
        ->toBe('2026-02-15T10:30:00.000000+09:00')
        ->and(publishedAt('2026-07-15T10:30:00', new DateTimeZone('Europe/Berlin')))
        ->toBe('2026-07-15T10:30:00.000000+02:00')
        ->and(publishedAt('2024-02-29'))->toBe('2024-02-29T00:00:00.000000+00:00');
});

test('the default time zone of PHP does not matter', function () {
    $previous = date_default_timezone_get();
    date_default_timezone_set('America/New_York');

    try {
        expect(publishedAt('2026-02-15T10:30:00'))->toBe('2026-02-15T10:30:00.000000+00:00');
    } finally {
        date_default_timezone_set($previous);
    }
});

test('anything else is null', function (?string $value) {
    expect(publishedAt($value))->toBeNull();
})->with([
    null, '', ' ', 'now', 'today', 'yesterday', '2 hours ago', 'next Monday', '+1 day', '@1700000000', '1700000000',
    '2026', '2026-02', '20260215', '15-02-2026', '02/03/2026', '2026/02/15', 'February 15, 2026',
    'Sun, 15 Feb 2026 10:30:00 +0000', '2026-02-30', '2025-02-29', '2026-13-01', '2026-00-10', '0000-01-01',
    '2026-02-15T24:00:00', '2026-02-15T10:60:00', '2026-02-15T10:30:60', '2026-02-15T10', '2026-02-15T10:30:00 EST',
    '2026-02-15T10:30:00+15:00', '2026-02-15T10:30:00+14:30', '2026-02-15T10:30:00+05:75', '2026-02-15Z',
    ' 2026-02-15', "2026-02-15\n", '2026-02-15T10:30:00.Z', '2026-2-5', '2026-02-15T10:30:00+05:30[Asia/Kolkata]',
]);
