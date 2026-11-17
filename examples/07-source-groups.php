<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use GlobalNewsScraper\Client;
use GlobalNewsScraper\Collector;
use GlobalNewsScraper\Source;
use GlobalNewsScraper\SourceGroup;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = (new Client)->setApifyKey($apiToken);

// Scenario: a brand monitor for a company that sells in Spain, Latin America and Brazil. Instead of listing
// hundreds of outlets, pick whole language groups and add a few single outlets and one collector on top.
$sources = [
    SourceGroup::Spanish,       // every Spanish-language outlet (Spain, Latin America, Mexico)
    SourceGroup::Portuguese,    // the Brazilian outlets
    Source::WashingtonPost,            // one English-language outlet on top
    Collector::Uk,              // everything the UK collector offers
];

// Every collector run is billed (start fee plus per article), so look at the runs before starting them:
// the group is expanded, duplicates are removed and no request is sent.
foreach ($client->plan($sources) as $run) {
    echo "{$run->collector->value}: ".count($run->sources)." sources\n";
}

// Always set a limit with groups: it applies to each collector run, so 4 runs with limit 20 return up to 80 articles.
// A rare search term keeps the article bill small, but every run still costs its start fee.
$result = $client->scrape(
    sources: $sources,
    searchQuery: 'Acme',
    startDate: new DateTimeImmutable('-1 day'),
    limit: 20,
);

echo "{$result->entries->count()} mentions\n";

$result->entries
    ->groupBy('collection')
    ->each(fn ($articles, $collection) => print "{$collection}: {$articles->count()}\n");

// Language groups are plain lists of sources: filter one before scraping to leave outlets out
$spanishWithoutMexico = collect(SourceGroup::Spanish->sources())
    ->reject(fn (Source $source) => in_array(Collector::Mx, $source->collectors(), true));

echo "Spanish sources outside the Mexico collector: {$spanishWithoutMexico->count()}\n";
