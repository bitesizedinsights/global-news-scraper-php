<?php

declare(strict_types=1);

namespace GlobalNewsScraper\DTO\Enrichment;

use GlobalNewsScraper\Support\Lenient;

/**
 * Result of `withTags`, read from `Article::$tags`.
 */
final readonly class Tags
{
    /**
     * @param  list<string>  $tags  Category tags, most relevant first; may be empty.
     */
    public function __construct(
        public array $tags,
        public ?string $model = null,
    ) {}

    /**
     * Null when `$data` has no `tags` list. Entries that are not strings are left out.
     *
     * @param  array<mixed>  $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $tags = Lenient::array($data['tags'] ?? null);

        if ($tags === null || ! array_is_list($tags)) {
            return null;
        }

        return new self(
            tags: array_values(array_filter($tags, is_string(...))),
            model: Lenient::string($data['model'] ?? null),
        );
    }
}
