<?php

declare(strict_types=1);

namespace GlobalNewsScraper\DTO;

use GlobalNewsScraper\Support\Value;
use Illuminate\Support\Collection;
use JsonSerializable;

final readonly class ScrapeResult implements JsonSerializable
{
    /**
     * @param  Collection<int, Article>  $entries  Merged articles of all collectors, deduplicated by URL.
     * @param  Collection<int, CollectorError>  $errors  Collectors whose run failed.
     */
    public function __construct(
        public Collection $entries,
        public Collection $errors,
    ) {}

    /** @param  array<mixed>  $data  Output of `toArray()`. */
    public static function fromArray(array $data): self
    {
        $entries = [];
        foreach (Value::array($data, 'entries') as $entry) {
            $entries[] = Article::fromArray(is_array($entry) ? $entry : []);
        }

        $errors = [];
        foreach (Value::array($data, 'errors') as $error) {
            $errors[] = CollectorError::fromArray(is_array($error) ? $error : []);
        }

        return new self(new Collection($entries), new Collection($errors));
    }

    /**
     * @return array{entries: list<array<string, mixed>>, errors: list<array{collector: string, message: string}>}
     */
    public function toArray(): array
    {
        return [
            'entries' => array_values($this->entries->map(static fn (Article $a): array => $a->toArray())->all()),
            'errors' => array_values($this->errors->map(static fn (CollectorError $e): array => $e->toArray())->all()),
        ];
    }

    /**
     * @return array{entries: list<array<string, mixed>>, errors: list<array{collector: string, message: string}>}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

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
