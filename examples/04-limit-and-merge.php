<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use GlobalNewsScraper\Client;
use GlobalNewsScraper\Source;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = (new Client)->setApifyKey($apiToken);

// The limit applies to each collector run: two collectors with limit 10 return up to 20 articles
$result = $client->scrape(
    sources: [Source::NewYorkTimes, Source::WashingtonPost, Source::BbcNews, Source::TheGuardian],
    limit: 10,
);

// Latest articles first, text pages only, five per collector
$latest = $result->entries
    ->filter(fn ($article) => $article->isArticle())
    ->sortByDesc('datePublish')
    ->groupBy('collection')
    ->map(fn ($articles) => $articles->take(5)->pluck('title'));

print_r($latest->toArray());
