<?php

declare(strict_types=1);

namespace GlobalNewsScraper\DTO;

use Illuminate\Support\Collection;

final readonly class ScrapeResult
{
    /**
     * @param  Collection<int, Article>  $entries  Merged articles of all collectors, deduplicated by URL.
     * @param  Collection<int, CollectorError>  $errors  Collectors whose run failed.
     */
    public function __construct(
        public Collection $entries,
        public Collection $errors,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors->isNotEmpty();
    }

    /**
     * True when every collector run succeeded.
     */
    public function isComplete(): bool
    {
        return $this->errors->isEmpty();
    }
}
