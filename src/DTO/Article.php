<?php

declare(strict_types=1);

namespace GlobalNewsScraper\DTO;

use DateTimeImmutable;
use DateTimeZone;
use GlobalNewsScraper\ArticleType;
use GlobalNewsScraper\DTO\Enrichment\DetectedLanguage;
use GlobalNewsScraper\DTO\Enrichment\FiveW1h;
use GlobalNewsScraper\DTO\Enrichment\FixedText;
use GlobalNewsScraper\DTO\Enrichment\Sentiment;
use GlobalNewsScraper\DTO\Enrichment\Summary;
use GlobalNewsScraper\DTO\Enrichment\Tags;
use GlobalNewsScraper\Support\ArticleDate;
use GlobalNewsScraper\Support\Lenient;
use GlobalNewsScraper\Support\Value;
use InvalidArgumentException;
use JsonSerializable;

final readonly class Article implements JsonSerializable
{
    /**
     * @param  string  $collection  Key of the collector that returned the article, e.g. `us` or `jp-en`.
     * @param  list<string>  $authors
     * @param  array<mixed>|null  $sentiment  Set when sentiment was requested and succeeded.
     * @param  array<mixed>|null  $tags  Set when tags were requested and succeeded.
     * @param  array<mixed>|null  $summary  Set when a summary was requested and succeeded.
     * @param  array<mixed>|null  $fiveW1h  Set when 5W1H was requested and succeeded.
     * @param  array<mixed>|null  $detectedLanguage  Set when language detection was requested and succeeded.
     * @param  array<mixed>|null  $fixedText  Set when text fixing was requested and succeeded.
     * @param  string|null  $error  Deprecated: reserved by the Actors and always null; failed articles are left out
     *                              of the dataset and failed runs are in `ScrapeResult::$errors`. Kept for
     *                              compatibility.
     */
    public function __construct(
        public string $url,
        public string $collection,
        public ?string $title = null,
        public array $authors = [],
        public ?string $datePublish = null,
        public ?string $description = null,
        public string $text = '',
        public ?string $language = null,
        public ?string $imageUrl = null,
        public string $sourceDomain = '',
        public ArticleType $type = ArticleType::Article,
        public ?array $sentiment = null,
        public ?array $tags = null,
        public ?array $summary = null,
        public ?array $fiveW1h = null,
        public ?array $detectedLanguage = null,
        public ?array $fixedText = null,
        public ?string $error = null,
    ) {}

    /**
     * @param  array<mixed>  $data  One dataset item of a collector Actor, or the output of `toArray()`.
     * @param  string|null  $collection  Collector key; defaults to the `collection` entry of `$data`.
     */
    public static function fromArray(array $data, ?string $collection = null): self
    {
        $collection ??= Value::string($data, 'collection');
        $type = Value::nullableString($data, 'type');

        return new self(
            url: Value::string($data, 'url'),
            collection: $collection,
            title: Value::nullableString($data, 'article_title'),
            authors: Value::stringList($data, 'authors'),
            datePublish: Value::nullableString($data, 'date_publish'),
            description: Value::nullableString($data, 'article_description'),
            text: Value::nullableString($data, 'text') ?? '',
            language: Value::nullableString($data, 'language'),
            imageUrl: Value::nullableString($data, 'image_url'),
            sourceDomain: Value::nullableString($data, 'source_domain') ?? '',
            type: $type === null ? ArticleType::Article : self::type($type),
            sentiment: Lenient::array($data['sentiment'] ?? null),
            tags: Lenient::array($data['tags'] ?? null),
            summary: Lenient::array($data['summary'] ?? null),
            fiveW1h: Lenient::array($data['five_w1h'] ?? null),
            detectedLanguage: Lenient::array($data['detected_language'] ?? null),
            fixedText: Lenient::array($data['fixed_text'] ?? null),
            error: Value::nullableString($data, 'error'),
        );
    }

    /**
     * The article in the Actor's dataset field names, plus `collection`; `fromArray()` restores it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'collection' => $this->collection,
            'article_title' => $this->title,
            'authors' => $this->authors,
            'date_publish' => $this->datePublish,
            'article_description' => $this->description,
            'text' => $this->text,
            'language' => $this->language,
            'image_url' => $this->imageUrl,
            'source_domain' => $this->sourceDomain,
            'type' => $this->type->value,
            'sentiment' => $this->sentiment,
            'tags' => $this->tags,
            'summary' => $this->summary,
            'five_w1h' => $this->fiveW1h,
            'detected_language' => $this->detectedLanguage,
            'fixed_text' => $this->fixedText,
            'error' => $this->error,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Video and audio pages have little or no text; filter on this to keep articles only.
     */
    public function isArticle(): bool
    {
        return $this->type === ArticleType::Article;
    }

    public function hasEnrichment(): bool
    {
        return $this->sentiment !== null
            || $this->tags !== null
            || $this->summary !== null
            || $this->fiveW1h !== null
            || $this->detectedLanguage !== null
            || $this->fixedText !== null;
    }

    /**
     * `$datePublish` as a date. The Actors send ISO 8601, with or without a UTC offset, as the page states it.
     * Null when there is no date, or for anything but a complete ISO 8601 date (`2026-02-15`, or with a time such as
     * `2026-02-15T10:30:00.5+09:00`): empty, relative, free-text, ambiguous (`02/03/2026`, `10:30 EST`) and
     * impossible dates (`2026-02-30`).
     *
     * A value with an offset or `Z` keeps it. A value without one is read as wall-clock time in `$assumedTimeZone`,
     * UTC when null: the real zone is not known, so such a date can be off by up to a day.
     */
    public function publishedAt(?DateTimeZone $assumedTimeZone = null): ?DateTimeImmutable
    {
        return $this->datePublish === null ? null : ArticleDate::parse($this->datePublish, $assumedTimeZone);
    }

    /** Typed `$sentiment`; null without a result or with an unknown shape. */
    public function sentimentResult(): ?Sentiment
    {
        return $this->sentiment === null ? null : Sentiment::tryFromArray($this->sentiment);
    }

    /** Typed `$tags`; null without a result or with an unknown shape. */
    public function tagsResult(): ?Tags
    {
        return $this->tags === null ? null : Tags::tryFromArray($this->tags);
    }

    /** Typed `$summary`; null without a result or with an unknown shape. */
    public function summaryResult(): ?Summary
    {
        return $this->summary === null ? null : Summary::tryFromArray($this->summary);
    }

    /** Typed `$fiveW1h`; null without a result or with an unknown shape. */
    public function fiveW1hResult(): ?FiveW1h
    {
        return $this->fiveW1h === null ? null : FiveW1h::tryFromArray($this->fiveW1h);
    }

    /** Typed `$detectedLanguage`; null without a result or with an unknown shape. */
    public function detectedLanguageResult(): ?DetectedLanguage
    {
        return $this->detectedLanguage === null ? null : DetectedLanguage::tryFromArray($this->detectedLanguage);
    }

    /** Typed `$fixedText`; null without a result or with an unknown shape. */
    public function fixedTextResult(): ?FixedText
    {
        return $this->fixedText === null ? null : FixedText::tryFromArray($this->fixedText);
    }

    private static function type(string $type): ArticleType
    {
        return ArticleType::tryFrom($type)
            ?? throw new InvalidArgumentException("Field 'type' has unknown value '{$type}'");
    }
}
