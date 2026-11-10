<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use GlobalNewsScraper\Client;
use GlobalNewsScraper\Source;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = (new Client)->setApifyKey($apiToken);

// Add-ons are billed per enriched article on top of the article price (see the pricing section of the README)
$result = $client->scrape(
    sources: [Source::Reuters, Source::BbcNews],
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
    echo '  sentiment: '.json_encode($article->sentiment)."\n";
    echo '  tags: '.json_encode($article->tags)."\n";
    echo '  summary: '.json_encode($article->summary)."\n";
    echo '  5w1h: '.json_encode($article->fiveW1h)."\n";
    echo '  detected language: '.json_encode($article->detectedLanguage)."\n";
    echo '  fixed text: '.($article->fixedText === null ? 'not needed' : 'repaired')."\n";
}
