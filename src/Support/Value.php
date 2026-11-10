<?php

declare(strict_types=1);

namespace GlobalNewsScraper\Support;

use InvalidArgumentException;

/**
 * Typed access to decoded JSON objects.
 *
 * @internal
 */
final class Value
{
    /** @param  array<mixed>  $data */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException("Field '{$key}' must be a string");
        }

        return $value;
    }

    /** @param  array<mixed>  $data */
    public static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("Field '{$key}' must be a string or null");
        }

        return $value;
    }

    /** @param  array<mixed>  $data */
    public static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value)) {
            throw new InvalidArgumentException("Field '{$key}' must be an integer");
        }

        return $value;
    }

    /** @param  array<mixed>  $data */
    public static function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_int($value)) {
            throw new InvalidArgumentException("Field '{$key}' must be an integer or null");
        }

        return $value;
    }

    /** @param  array<mixed>  $data */
    public static function float(array $data, string $key): float
    {
        $value = $data[$key] ?? null;

        if (! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException("Field '{$key}' must be a number");
        }

        return (float) $value;
    }

    /** @param  array<mixed>  $data */
    public static function nullableFloat(array $data, string $key): ?float
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException("Field '{$key}' must be a number or null");
        }

        return (float) $value;
    }

    /** @param  array<mixed>  $data */
    public static function bool(array $data, string $key, bool $default = false): bool
    {
        $value = $data[$key] ?? $default;

        if (! is_bool($value)) {
            throw new InvalidArgumentException("Field '{$key}' must be a boolean");
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>|null
     */
    public static function nullableArray(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException("Field '{$key}' must be an object or null");
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function array(array $data, string $key): array
    {
        return self::nullableArray($data, $key)
            ?? throw new InvalidArgumentException("Field '{$key}' must be an array");
    }

    /**
     * @param  array<mixed>  $data
     * @return list<string>
     */
    public static function stringList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException("Field '{$key}' must be an array of strings");
        }

        $list = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException("Field '{$key}' must be an array of strings");
            }

            $list[] = $item;
        }

        return $list;
    }
}
