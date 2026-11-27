<?php

declare(strict_types=1);

use GlobalNewsScraper\DTO\Article;
use GlobalNewsScraper\DTO\Enrichment\LanguageProbability;
use GlobalNewsScraper\DTO\Enrichment\SentimentLabel;

const ENRICHMENT_ACCESSORS = [
    'sentimentResult', 'tagsResult', 'summaryResult', 'fiveW1hResult', 'detectedLanguageResult', 'fixedTextResult',
];

const ENRICHMENT_FIELDS = ['sentiment', 'tags', 'summary', 'five_w1h', 'detected_language', 'fixed_text'];

function enrichedWith(string $field, mixed $value): Article
{
    return Article::fromArray([$field => $value] + loadFixture('article_minimal'), 'us');
}

test('typed accessors read the shapes of the Actors', function () {
    $article = Article::fromArray(loadFixture('article_enriched'), 'uk');

    $sentiment = $article->sentimentResult();
    expect($sentiment?->label)->toBe(SentimentLabel::Positive)
        ->and($sentiment?->confidence)->toBe(0.6369)
        ->and($sentiment?->scores?->positive)->toBe(0.3)
        ->and($sentiment?->scores?->neutral)->toBe(0.7)
        ->and($sentiment?->scores?->negative)->toBe(0.0)
        ->and($sentiment?->scores?->compound)->toBe(0.6369)
        ->and($sentiment?->method)->toBe('vader')
        ->and($article->tagsResult()?->tags)->toBe(['Politics', 'Economy'])
        ->and($article->tagsResult()?->model)->toBe('gpt-4o-mini')
        ->and($article->summaryResult()?->text)->toBe('Short summary.')
        ->and($article->summaryResult()?->originalLength)->toBe(33)
        ->and($article->summaryResult()?->summaryLength)->toBe(14)
        ->and($article->summaryResult()?->compressionRatio)->toBe(0.4242)
        ->and($article->fiveW1hResult()?->who?->answer)->toBe('Example Ministry')
        ->and($article->fiveW1hResult()?->who?->confidence)->toBe(0.9)
        ->and($article->fiveW1hResult()?->why?->answer)->toBeNull()
        ->and($article->fiveW1hResult()?->how?->confidence)->toBe(0.0)
        ->and($article->detectedLanguageResult()?->language)->toBe('en')
        ->and($article->detectedLanguageResult()?->candidates)->toEqual([new LanguageProbability('en', 0.9999)])
        ->and($article->fixedTextResult()?->text)->toBe('Body text of the example article.')
        ->and($article->fixedTextResult()?->changed)->toBeFalse()
        ->and($article->fixedTextResult()?->method)->toBe('ftfy');
});

test('typed accessors are null without add-ons', function () {
    $article = Article::fromArray(loadFixture('article_full'), 'us');

    foreach (ENRICHMENT_ACCESSORS as $accessor) {
        expect($article->{$accessor}())->toBeNull();
    }
});

test('unknown shapes never throw and give null', function (array $shape) {
    foreach (ENRICHMENT_FIELDS as $i => $field) {
        $article = enrichedWith($field, $shape);

        expect($article->{ENRICHMENT_ACCESSORS[$i]}())->toBeNull()
            ->and($article->toArray()[$field])->toBe($shape)
            ->and(Article::fromArray($article->toArray()))->toEqual($article);
    }
})->with([
    'empty' => [[]],
    'list' => [['a', 'b']],
    'error item' => [['error' => true, 'error_msg' => 'failed']],
    'wrong types' => [[
        'sentiment' => 5, 'tags' => 'x', 'summary' => ['x'], 'result' => 'x', 'language' => 7, 'fixed_text' => null,
    ]],
    'nested wrong types' => [['tags' => ['a' => 'b'], 'result' => ['who' => 'Example'], 'sentiment' => 'mixed']],
]);

test('a value that is no object never breaks hydration', function (mixed $value) {
    foreach (ENRICHMENT_FIELDS as $i => $field) {
        $article = enrichedWith($field, $value);

        expect($article->{ENRICHMENT_ACCESSORS[$i]}())->toBeNull()
            ->and($article->toArray()[$field])->toBeNull()
            ->and($article->hasEnrichment())->toBeFalse();
    }
})->with(['string' => ['x'], 'int' => [5], 'float' => [0.5], 'bool' => [false]]);

test('optional values of another type are null or left out', function () {
    $sentiment = enrichedWith('sentiment', ['sentiment' => 'neutral', 'confidence' => '1', 'scores' => 'x'])
        ->sentimentResult();
    $tags = enrichedWith('tags', ['tags' => ['A', 5, null, 'B']])->tagsResult();
    $summary = enrichedWith('summary', ['summary' => 'S', 'stats' => ['original_length' => '3']])->summaryResult();
    $fiveW1h = enrichedWith('five_w1h', ['result' => ['who' => ['answer' => ['a'], 'confidence' => 1]]])
        ->fiveW1hResult();
    $language = enrichedWith('detected_language', [
        'language' => 'de', 'all_languages' => ['x', ['lang' => 'de'], ['lang' => 'nl', 'prob' => 1]],
    ])->detectedLanguageResult();
    $fixed = enrichedWith('fixed_text', ['fixed_text' => 'T', 'changed' => 'yes'])->fixedTextResult();

    expect($sentiment?->confidence)->toBeNull()
        ->and($sentiment?->scores)->toBeNull()
        ->and($tags?->tags)->toBe(['A', 'B'])
        ->and(enrichedWith('tags', ['tags' => []])->tagsResult()?->tags)->toBe([])
        ->and($summary?->originalLength)->toBeNull()
        ->and($fiveW1h?->who?->answer)->toBeNull()
        ->and($fiveW1h?->who?->confidence)->toBe(1.0)
        ->and($fiveW1h?->what)->toBeNull()
        ->and($language?->confidence)->toBeNull()
        ->and($language?->candidates)->toEqual([new LanguageProbability('nl', 1.0)])
        ->and($fixed?->changed)->toBeNull();
});

test('non finite numbers are null', function () {
    expect(enrichedWith('sentiment', ['sentiment' => 'negative', 'confidence' => INF])->sentimentResult()?->confidence)
        ->toBeNull();
});
