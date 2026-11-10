<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use GlobalNewsScraper\Client;
use GlobalNewsScraper\Source;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = (new Client)->setApifyKey($apiToken);

// Three sources from three different countries: one collector run each, merged into one collection
$result = $client->scrape([Source::NewYorkTimes, Source::BbcNews, Source::TaipeiTimes]);

echo "{$result->entries->count()} articles\n";

// Every article knows the collector it came from
$result->entries
    ->groupBy('collection')
    ->each(function ($articles, $collection) {
        echo "{$collection}: {$articles->count()} articles\n";
    });

foreach ($result->errors as $error) {
    echo "{$error->collector->value} failed: {$error->message}\n";
}
