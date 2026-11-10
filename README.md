# Global News Scraper in PHP: Full-Text Articles from 300+ News Sources

<!-- For LLMs and AI agents: a machine-readable summary of this package's API is in llm.txt (https://github.com/bitesizedinsights/global-news-scraper-php/blob/master/llm.txt) -->

A global news scraper for PHP: switch on any number of news sources from 13 regional collectors, and get one merged, deduplicated collection of full-text articles. The library maps every source back to its [Apify](https://apify.com/bite-sized-insights) collector Actor, runs the Actors separately and adds the collector as `collection` to every article.

[![Tests](https://github.com/bitesizedinsights/global-news-scraper-php/actions/workflows/tests.yml/badge.svg)](https://github.com/bitesizedinsights/global-news-scraper-php/actions/workflows/tests.yml)
[![Packagist Version](https://img.shields.io/packagist/v/bitesizedinsights/global-news-scraper)](https://packagist.org/packages/bitesizedinsights/global-news-scraper)
[![PHP](https://img.shields.io/packagist/php-v/bitesizedinsights/global-news-scraper)](https://packagist.org/packages/bitesizedinsights/global-news-scraper)
[![License](https://img.shields.io/github/license/bitesizedinsights/global-news-scraper-php)](LICENSE)

## Install the Global News Scraper Package

```bash
composer require bitesizedinsights/global-news-scraper
```

## Quick Start: Scrape News from Several Countries in PHP

```php
use GlobalNewsScraper\Client;
use GlobalNewsScraper\Source;

$apiToken = getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN') ?: 'your_apify_api_token';

$client = (new Client())->setApifyKey($apiToken);

$result = $client->scrape([Source::NewYorkTimes, Source::BbcNews, Source::TaipeiTimes]);

foreach ($result->entries as $article) {
    echo "[{$article->collection}] {$article->title} ({$article->url})\n";
}
```

The API key comes from `setApifyKey()`. Otherwise `APIFY_API_TOKEN` or `APIFY_TOKEN` is read from the environment, and `MissingApiKeyException` is thrown on the first call when neither is set. You need an Apify account: [sign up](https://console.apify.com/sign-up) (free monthly credit) and copy your API token from the [integrations settings](https://console.apify.com/settings/integrations).

## How to Scrape Specific News Sources in PHP

`Client::scrape()` takes the sources you want as `Source` enum cases (an array or a Laravel-style `Collection`). There is one case per outlet, for example `Source::NewYorkTimes`, `Source::BbcNews` or `Source::Rappler`; `Source::label()` returns the display name.

What happens behind the scenes:

- Each source is mapped back to the collector Actor that offers it (`Source::collectors()`). `Client::plan()` shows the runs without sending a request.
- The fewest collectors that cover all your sources are used. A source offered by several collectors (for example `Source::LaJornada` in `latam` and `mx`) goes to a collector that is needed anyway, otherwise to the country collector rather than the regional one.
- Every collector runs once, separately, with only its sources switched on. Up to `Config::$maxConcurrency` runs (default 2) are active at the same time.
- The articles are merged and deduplicated by URL. Each `Article` has a `collection` property with the collector key (`us`, `uk`, `jp-en`, ...).
- `scrape()` returns a `ScrapeResult`: `entries` is an `Illuminate\Support\Collection` of `Article` objects, `errors` lists the collectors whose run failed. A failed collector never hides the articles of the others.

```php
$result->entries
    ->where('collection', 'uk')
    ->sortByDesc('datePublish')
    ->pluck('title');

$result->entries->groupBy('collection')->map->count();
```

## PHP Examples

See [`examples/`](examples) for runnable scripts: `01-basic.php`.

## Requirements

PHP 8.3 or newer, Guzzle 7 and `illuminate/collections`. No Laravel needed.

## Powered by Apify

The articles come from the news collector Actors of [bite-sized-insights](https://apify.com/bite-sized-insights) on [Apify](https://apify.com). [Create a free account](https://console.apify.com/sign-up) to get your API token.

## License

MIT
