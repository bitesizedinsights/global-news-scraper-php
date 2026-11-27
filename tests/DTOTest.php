<?php

declare(strict_types=1);

use GlobalNewsScraper\ArticleType;
use GlobalNewsScraper\Collector;
use GlobalNewsScraper\DTO\Article;
use GlobalNewsScraper\DTO\CollectorError;
use GlobalNewsScraper\DTO\RunPlan;
use GlobalNewsScraper\DTO\ScrapeResult;
use GlobalNewsScraper\Source;
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

    $raw = loadFixture('article_enriched');

    expect($article->authors)->toBe(['Reporter One', 'Reporter Two'])
        ->and($article->sentiment)->toBe($raw['sentiment'])
        ->and($article->tags)->toBe($raw['tags'])
        ->and($article->summary)->toBe($raw['summary'])
        ->and($article->fiveW1h)->toBe($raw['five_w1h'])
        ->and($article->detectedLanguage)->toBe($raw['detected_language'])
        ->and($article->fixedText)->toBe($raw['fixed_text'])
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

test('article round trips through toArray and json', function (string $fixture) {
    $article = Article::fromArray(loadFixture($fixture), 'us');

    $viaArray = Article::fromArray($article->toArray());
    $viaJson = Article::fromArray(json_decode(json_encode($article, JSON_THROW_ON_ERROR), true));

    expect($viaArray)->toEqual($article)
        ->and($viaJson)->toEqual($article);
})->with(['article_full', 'article_minimal', 'article_enriched', 'article_video']);

test('article toArray uses the Actor field names and carries the collection', function () {
    $array = Article::fromArray(loadFixture('article_full'), 'jp-en')->toArray();

    expect($array)->toHaveKeys(['article_title', 'date_publish', 'source_domain', 'five_w1h', 'collection'])
        ->and($array['collection'])->toBe('jp-en')
        ->and($array['type'])->toBe('article');
});

test('article fromArray prefers the explicit collection argument', function () {
    $data = Article::fromArray(loadFixture('article_full'), 'us')->toArray();

    expect(Article::fromArray($data, 'uk')->collection)->toBe('uk');
});

test('collector error and run plan round trip', function () {
    $error = new CollectorError(Collector::Us, 'Rate limited');
    $plan = new RunPlan(Collector::Uk, [Source::WashingtonPost, Source::BbcNews]);

    expect(CollectorError::fromArray(json_decode(json_encode($error), true)))->toEqual($error)
        ->and(RunPlan::fromArray(json_decode(json_encode($plan), true)))->toEqual($plan)
        ->and($plan->toArray())->toBe(['collector' => 'uk', 'sources' => ['washington_post', 'bbc_news']]);
});

test('scrape result round trips', function () {
    $result = new ScrapeResult(
        new Collection([Article::fromArray(loadFixture('article_enriched'), 'us')]),
        new Collection([new CollectorError(Collector::Fr, 'Failed')]),
    );

    $restored = ScrapeResult::fromArray(json_decode(json_encode($result, JSON_THROW_ON_ERROR), true));

    expect($restored)->toEqual($result);
});

test('collector error with a run ID round trips and null run ID keeps the output unchanged', function () {
    $with = new CollectorError(Collector::Us, 'Timed out', 'run-1');
    $without = new CollectorError(Collector::Us, 'Rate limited');

    expect($with->toArray())->toBe(['collector' => 'us', 'message' => 'Timed out', 'runId' => 'run-1'])
        ->and(CollectorError::fromArray(json_decode(json_encode($with), true)))->toEqual($with)
        ->and(json_encode($without))->toBe('{"collector":"us","message":"Rate limited"}')
        ->and(CollectorError::fromArray(['collector' => 'us', 'message' => 'x', 'runId' => null])->runId)->toBeNull()
        ->and(fn () => CollectorError::fromArray(['collector' => 'us', 'message' => 'x', 'runId' => 5]))
        ->toThrow(InvalidArgumentException::class);
});
