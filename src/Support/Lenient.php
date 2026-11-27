<?php

declare(strict_types=1);

namespace GlobalNewsScraper\Support;

/**
 * Tolerant access to the add-on objects: a missing value or one of another type is null, nothing throws.
 *
 * @internal
 */
final class Lenient
{
    public static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    public static function int(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    public static function float(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        return is_finite((float) $value) ? (float) $value : null;
    }

    /** @return array<mixed>|null */
    public static function array(mixed $value): ?array
    {
        return is_array($value) ? $value : null;
    }
}
