<?php

declare(strict_types=1);

namespace GlobalNewsScraper\Support;

use GlobalNewsScraper\Source;

/**
 * Finds sources by name, for `Source::fromLabel()` and `Source::search()`. Only the cases of `Source` are looked at,
 * so sources of disabled collectors are never found.
 *
 * @internal
 */
final class SourceLookup
{
    public static function fromLabel(string $label): ?Source
    {
        $label = self::normalize($label);

        if ($label === '') {
            return null;
        }

        $pattern = '/\A'.preg_quote($label, '/').'\z/iu';
        $exact = [];
        $anyCase = [];

        foreach (Source::cases() as $source) {
            if ($source->label() === $label) {
                $exact[] = $source;
            }

            if (preg_match($pattern, $source->label()) === 1) {
                $anyCase[] = $source;
            }
        }

        // Several sources with the same name are never guessed between
        $matches = $exact === [] ? $anyCase : $exact;

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @return list<Source> */
    public static function search(string $query): array
    {
        $query = self::normalize($query);

        if ($query === '') {
            return [];
        }

        $quoted = preg_quote($query, '/');
        $named = [];
        $others = [];

        foreach (Source::cases() as $source) {
            if (preg_match('/\A'.$quoted.'\z/iu', $source->label()) === 1) {
                $named[] = $source;
            } elseif (
                preg_match('/'.$quoted.'/iu', $source->label()) === 1
                || preg_match('/'.$quoted.'/iu', $source->value) === 1
            ) {
                $others[] = $source;
            }
        }

        return [...$named, ...$others];
    }

    /** Trimmed, with single spaces; empty for invalid UTF-8. */
    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $text));
    }
}
