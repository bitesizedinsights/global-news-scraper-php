<?php

declare(strict_types=1);

namespace GlobalNewsScraper\DTO\Enrichment;

use GlobalNewsScraper\Support\Lenient;

/**
 * Result of `withFixText`, read from `Article::$fixedText`.
 */
final readonly class FixedText
{
    /**
     * @param  string  $text  The checked text; the same as the input when nothing needed repair.
     * @param  bool|null  $changed  True when the text was repaired (only these are billed).
     */
    public function __construct(
        public string $text,
        public ?bool $changed = null,
        public ?string $method = null,
    ) {}

    /**
     * Null when `$data` has no `fixed_text` string.
     *
     * @param  array<mixed>  $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $text = Lenient::string($data['fixed_text'] ?? null);

        if ($text === null) {
            return null;
        }

        $changed = $data['changed'] ?? null;

        return new self(
            text: $text,
            changed: is_bool($changed) ? $changed : null,
            method: Lenient::string($data['method'] ?? null),
        );
    }
}
