<?php

declare(strict_types=1);

namespace GlobalNewsScraper\Support;

/**
 * Checks IDs of Apify objects before they become part of a request path: only characters that cannot change the path
 * (no slash, dot, `?`, `#` or `%`), 1 to 64 of them.
 *
 * @internal
 */
final class RunId
{
    public static function isValid(string $id): bool
    {
        return preg_match('/\A[A-Za-z0-9_~-]{1,64}\z/', $id) === 1;
    }
}
