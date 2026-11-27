<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use GlobalNewsScraper\Client;
use GlobalNewsScraper\Source;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = (new Client)->setApifyKey($apiToken);

// Add-ons are billed per enriched article on top of the article price (see the pricing section of the README)
$result = $client->scrape(
    sources: [Source::WashingtonPost, Source::BbcNews],
    limit: 5,
    withSentiment: true,
    withTags: true,
    withSummary: true,
    with5w1h: true,
    withLanguageDetection: true,
    withFixText: true,
);

foreach ($result->entries as $article) {
    echo "[{$article->collection}] {$article->title}\n";
    echo '  sentiment: '.($article->sentimentResult()?->label->value ?? '-')."\n";
    echo '  tags: '.implode(', ', $article->tagsResult()?->tags ?? [])."\n";
    echo '  summary: '.($article->summaryResult()?->text ?? '-')."\n";
    echo '  who: '.($article->fiveW1hResult()?->who?->answer ?? '-')."\n";
    echo '  detected language: '.($article->detectedLanguageResult()?->language ?? '-')."\n";
    echo '  fixed text: '.($article->fixedTextResult()?->changed === true ? 'repaired' : 'not needed')."\n";
}
