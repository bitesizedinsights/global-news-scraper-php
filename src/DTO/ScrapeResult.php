<?php

declare(strict_types=1);

namespace GlobalNewsScraper\DTO;

use DateTimeInterface;
use DateTimeZone;
use GlobalNewsScraper\Exception\ExportException;
use GlobalNewsScraper\Exception\InvalidInputException;
use GlobalNewsScraper\ExportFormat;
use GlobalNewsScraper\Support\ArticleDate;
use GlobalNewsScraper\Support\Exporter;
use GlobalNewsScraper\Support\FileWriter;
use GlobalNewsScraper\Support\Value;
use Illuminate\Support\Collection;
use JsonException;
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

    /**
     * `toArray()` as JSON. Invalid UTF-8 bytes in scraped text become U+FFFD instead of failing.
     *
     * @param  int  $flags  Further `json_encode()` flags, e.g. `JSON_PRETTY_PRINT`.
     *
     * @throws JsonException for data JSON cannot hold, such as an infinite number
     */
    public function toJson(int $flags = 0): string
    {
        return Exporter::json($this->toArray(), $flags);
    }

    /**
     * Writes the result to a local file. The write is atomic: the file is complete or untouched. An existing file is
     * only replaced with `$overwrite`; it then keeps its permissions, and a symbolic link is replaced, not followed.
     * The directory must exist.
     *
     * JSON holds articles and errors (`toJson()`). JSON Lines and CSV hold the articles only, so check `$errors`
     * yourself. CSV has the columns of `Article::toArray()`, lists and objects as JSON, every field quoted, and a
     * leading apostrophe before any cell a spreadsheet could run as a formula.
     *
     * @param  ExportFormat|null  $format  Null takes it from the extension: .json, .jsonl, .ndjson or .csv.
     *
     * @throws InvalidInputException for an unknown extension without a format, or no local path
     * @throws ExportException when the file exists without `$overwrite` or cannot be written
     * @throws JsonException for data JSON cannot hold; nothing is written then
     */
    public function saveTo(string $path, ?ExportFormat $format = null, bool $overwrite = false): void
    {
        FileWriter::write($path, Exporter::render($this, $format ?? Exporter::format($path)), $overwrite);
    }

    /**
     * A new result without video and audio pages (`Article::isArticle()`), with the same errors.
     */
    public function onlyArticles(): self
    {
        return $this->filtered(static fn (Article $article): bool => $article->isArticle());
    }

    /**
     * A new result with the articles published at or after `$since`, with the same errors. This filters what was
     * already fetched and billed; use `startDate` of `scrape()` to fetch less.
     *
     * Dates are compared as moments in time with `Article::publishedAt($assumedTimeZone)`. A string is read the same
     * way, so `2026-02-15` is that day's midnight. Articles without a usable date are dropped unless `$keepUndated`
     * is true (the Actors' `startDate` filter keeps them).
     *
     * @throws InvalidInputException for a string that is no ISO 8601 date
     */
    public function since(
        DateTimeInterface|string $since,
        bool $keepUndated = false,
        ?DateTimeZone $assumedTimeZone = null,
    ): self {
        if (is_string($since)) {
            $since = ArticleDate::parse($since, $assumedTimeZone)
                ?? throw new InvalidInputException('since must be an ISO 8601 date or a DateTimeInterface');
        }

        return $this->filtered(static function (Article $article) use ($since, $keepUndated, $assumedTimeZone): bool {
            $published = $article->publishedAt($assumedTimeZone);

            return $published === null ? $keepUndated : $published >= $since;
        });
    }

    /**
     * One result per collector key (`Article::$collection`), each with that collector's articles and errors. A
     * collector with only errors has a group without articles; one with neither has no group (see
     * `Results::emptyCollectors()`). Groups come in the order of the articles, those with only errors last.
     *
     * @return Collection<string, self>
     */
    public function groupByCollection(): Collection
    {
        $entries = [];
        $errors = [];

        foreach ($this->entries as $article) {
            $entries[$article->collection][] = $article;
        }

        foreach ($this->errors as $error) {
            $entries[$error->collector->value] ??= [];
            $errors[$error->collector->value][] = $error;
        }

        $groups = [];

        foreach ($entries as $key => $articles) {
            $groups[$key] = new self(new Collection($articles), new Collection($errors[$key] ?? []));
        }

        return new Collection($groups);
    }

    /** @param  callable(Article): bool  $keep */
    private function filtered(callable $keep): self
    {
        return new self(
            new Collection(array_values(array_filter($this->entries->all(), $keep))),
            new Collection($this->errors->all()),
        );
    }
}
