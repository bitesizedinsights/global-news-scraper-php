<?php

declare(strict_types=1);

use GlobalNewsScraper\ArticleType;
use GlobalNewsScraper\Collector;
use GlobalNewsScraper\DTO\Article;
use GlobalNewsScraper\DTO\CollectorError;
use GlobalNewsScraper\DTO\ScrapeResult;
use Illuminate\Support\Collection;

test('article maps a full dataset item and carries the collection', function () {
    $article = Article::fromArray(loadFixture('article_full'), 'us');

    expect($article->url)->toBe('https://www.nytimes.com/2026/02/15/us/example-headline.html')
        ->and($article->collection)->toBe('us')
        ->and($article->title)->toBe('Example article headline')
        ->and($article->authors)->toBe(['Author Name'])
        ->and($article->datePublish)->toBe('2026-02-15T10:30:00')
        ->and($article->description)->toBe('Brief summary of the article content.')
        ->and($article->text)->toBe('Full article text extracted from the page...')
        ->and($article->language)->toBe('en')
        ->and($article->imageUrl)->toBe('https://static01.nyt.com/images/example.jpg')
        ->and($article->sourceDomain)->toBe('www.nytimes.com')
        ->and($article->type)->toBe(ArticleType::Article)
        ->and($article->isArticle())->toBeTrue()
        ->and($article->hasEnrichment())->toBeFalse()
        ->and($article->error)->toBeNull();
});

test('article maps enrichment objects as raw arrays', function () {
    $article = Article::fromArray(loadFixture('article_enriched'), 'uk');

    expect($article->authors)->toBe(['Reporter One', 'Reporter Two'])
        ->and($article->sentiment)->toBe(['sentiment' => 'positive'])
        ->and($article->tags)->toBe(['tags' => ['politics']])
        ->and($article->summary)->toBe(['summary' => 'Short summary.'])
        ->and($article->fiveW1h)->toBe(['who' => 'Example'])
        ->and($article->detectedLanguage)->toBe(['language' => 'en'])
        ->and($article->fixedText)->toBe(['text' => 'Body text of the example article.'])
        ->and($article->hasEnrichment())->toBeTrue();
});

test('article needs only a URL', function () {
    $article = Article::fromArray(loadFixture('article_minimal'), 'es');

    expect($article->url)->toBe('https://example.com/news/minimal')
        ->and($article->title)->toBeNull()
        ->and($article->authors)->toBe([])
        ->and($article->text)->toBe('')
        ->and($article->sourceDomain)->toBe('')
        ->and($article->type)->toBe(ArticleType::Article);
});

test('article reports video pages', function () {
    $article = Article::fromArray(loadFixture('article_video'), 'uk');

    expect($article->type)->toBe(ArticleType::Video)->and($article->isArticle())->toBeFalse();
});

test('article rejects invalid fields', function (array $patch, string $message) {
    expect(fn () => Article::fromArray($patch + loadFixture('article_full'), 'us'))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'missing url' => [['url' => null], "Field 'url' must be a string"],
    'url not a string' => [['url' => 5], "Field 'url' must be a string"],
    'authors not a list' => [['authors' => 'x'], "Field 'authors' must be an array of strings"],
    'author not a string' => [['authors' => [1]], "Field 'authors' must be an array of strings"],
    'unknown type' => [['type' => 'podcast'], "Field 'type' has unknown value 'podcast'"],
    'sentiment not an object' => [['sentiment' => 'x'], "Field 'sentiment' must be an object or null"],
]);

test('article type enum values', function () {
    expect(ArticleType::tryFrom('audio'))->toBe(ArticleType::Audio)->and(ArticleType::tryFrom('x'))->toBeNull();
});

test('scrape result reports errors', function () {
    $clean = new ScrapeResult(new Collection, new Collection);
    $failed = new ScrapeResult(new Collection, new Collection([new CollectorError(Collector::Uk, 'boom')]));

    expect($clean->hasErrors())->toBeFalse()
        ->and($clean->isComplete())->toBeTrue()
        ->and($failed->hasErrors())->toBeTrue()
        ->and($failed->isComplete())->toBeFalse()
        ->and($failed->errors->first()->collector)->toBe(Collector::Uk);
});
