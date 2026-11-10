<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use GlobalNewsScraper\Client;
use GlobalNewsScraper\Source;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = (new Client)->setApifyKey($apiToken);

// Only articles whose title, text or URL contain the keyword
$result = $client->scrape(
    sources: [Source::Reuters, Source::TheGuardian, Source::Rappler],
    searchQuery: 'semiconductor',
);

foreach ($result->entries as $article) {
    echo "[{$article->collection}] {$article->title}\n";
}
