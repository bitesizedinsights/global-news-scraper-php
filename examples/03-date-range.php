<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use GlobalNewsScraper\Client;
use GlobalNewsScraper\Source;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = (new Client)->setApifyKey($apiToken);

// Articles of the last week; strings (YYYY-MM-DD) and DateTimeInterface objects both work
$result = $client->scrape(
    sources: [Source::Reuters, Source::BbcNews],
    startDate: new DateTimeImmutable('-7 days'),
    endDate: date('Y-m-d'),
);

foreach ($result->entries->sortByDesc('datePublish') as $article) {
    echo "{$article->datePublish} [{$article->collection}] {$article->title}\n";
}
