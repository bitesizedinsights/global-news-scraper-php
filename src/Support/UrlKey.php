<?php

declare(strict_types=1);

namespace GlobalNewsScraper\Support;

/**
 * Comparison key of an article URL for dedupe. It is never shown or requested: articles keep their original URL.
 * Only differences that do not change the page are removed, so two different articles never share a key.
 */
final class UrlKey
{
    /** Query parameters that only track the visit; `utm_*` is matched by prefix. */
    private const TRACKING_PARAMETERS = [
        '_ga', '_gl', 'at_campaign', 'at_medium', 'dclid', 'fbclid', 'gbraid', 'gclid', 'igshid', 'mc_cid', 'mc_eid',
        'msclkid', 'wbraid', 'yclid',
    ];

    public static function of(string $url): string
    {
        $trimmed = trim($url);
        $parts = parse_url($trimmed);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $trimmed;
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme !== 'http' && $scheme !== 'https') {
            return $trimmed;
        }

        $host = rtrim(strtolower($parts['host']), '.');
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        $port = $parts['port'] ?? null;

        if ($port !== null && $port !== ($scheme === 'https' ? 443 : 80)) {
            $host .= ':'.$port;
        }

        if (isset($parts['user'])) {
            $host = $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@'.$host;
        }

        $path = rtrim(self::upperEscapes($parts['path'] ?? ''), '/');
        $query = self::query($parts['query'] ?? '');
        $fragment = $parts['fragment'] ?? '';

        // `#/...` and `#!...` are routes of single-page sites; any other fragment is a position within one page.
        $route = str_starts_with($fragment, '/') || str_starts_with($fragment, '!');

        return '//'.$host.($path === '' ? '/' : $path)
            .($query === '' ? '' : '?'.$query)
            .($route ? '#'.$fragment : '');
    }

    /** Drops tracking and empty parameters and sorts by name; repeated names keep their relative order. */
    private static function query(string $query): string
    {
        $kept = [];

        foreach (explode('&', $query) as $pair) {
            $name = urldecode(explode('=', $pair, 2)[0]);
            $lower = strtolower($name);

            if ($pair === '' || str_starts_with($lower, 'utm_') || in_array($lower, self::TRACKING_PARAMETERS, true)) {
                continue;
            }

            $kept[] = [$name, self::upperEscapes($pair)];
        }

        usort($kept, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));

        return implode('&', array_column($kept, 1));
    }

    /** `%c3%a9` and `%C3%A9` are the same bytes. */
    private static function upperEscapes(string $value): string
    {
        return (string) preg_replace_callback(
            '/%[0-9a-fA-F]{2}/',
            static fn (array $match): string => strtoupper($match[0]),
            $value,
        );
    }
}
