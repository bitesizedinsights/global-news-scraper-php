<?php

declare(strict_types=1);

namespace GlobalNewsScraper\DTO;

use GlobalNewsScraper\ArticleType;
use GlobalNewsScraper\Support\Value;
use InvalidArgumentException;

final readonly class Article
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

    /** @param  array<mixed>  $data  One dataset item of a collector Actor. */
    public static function fromArray(array $data, string $collection): self
    {
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
            sentiment: Value::nullableArray($data, 'sentiment'),
            tags: Value::nullableArray($data, 'tags'),
            summary: Value::nullableArray($data, 'summary'),
            fiveW1h: Value::nullableArray($data, 'five_w1h'),
            detectedLanguage: Value::nullableArray($data, 'detected_language'),
            fixedText: Value::nullableArray($data, 'fixed_text'),
            error: Value::nullableString($data, 'error'),
        );
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

    private static function type(string $type): ArticleType
    {
        return ArticleType::tryFrom($type)
            ?? throw new InvalidArgumentException("Field 'type' has unknown value '{$type}'");
    }
}
