<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use GlobalNewsScraper\Client;
use GlobalNewsScraper\Exception\ApiException;
use GlobalNewsScraper\Exception\InvalidInputException;
use GlobalNewsScraper\Exception\MissingApiKeyException;
use GlobalNewsScraper\Source;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = (new Client)->setApifyKey($apiToken);

try {
    $result = $client->scrape([Source::WashingtonPost, Source::BbcNews], limit: 5);
} catch (InvalidInputException $e) {
    // Empty sources or an invalid option: nothing was sent
    exit("Invalid input: {$e->getMessage()}\n");
} catch (MissingApiKeyException $e) {
    exit("No Apify API key: {$e->getMessage()}\n");
} catch (ApiException $e) {
    exit("API error: {$e->getMessage()}\n");
}

// A failing collector run (rate limit, failed Actor run, ...) does not throw: the other collectors still deliver
foreach ($result->errors as $error) {
    echo "Collector {$error->collector->value} failed: {$error->message}\n";
}

echo $result->isComplete() ? "All collectors succeeded\n" : "Partial result\n";
echo "{$result->entries->count()} articles\n";
