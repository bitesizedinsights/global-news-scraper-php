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

## How to Search News Articles by Keyword in PHP

Pass `searchQuery` to keep only articles whose title, text or URL contain the keyword (1 to 100 characters). It is sent to every collector run.

```php
$result = $client->scrape(
    sources: [Source::Reuters, Source::TheGuardian, Source::Rappler],
    searchQuery: 'semiconductor',
);
```

Keyword filtering can make a collector process more articles than it returns.

## How to Filter News by Date Range in PHP

`startDate` and `endDate` keep articles published on or after / on or before a day. Pass `YYYY-MM-DD` strings or `DateTimeInterface` objects. `startDate` must not be in the future, `endDate` not older than 5 years and not before `startDate`; invalid dates throw `InvalidInputException` before any request is sent.

```php
$result = $client->scrape(
    sources: [Source::Reuters, Source::BbcNews],
    startDate: new DateTimeImmutable('-7 days'),
    endDate: date('Y-m-d'),
);
```

## How to Limit the Number of News Articles in PHP

`limit` is the maximum number of articles per collector run (0 to 10000, 0 means unlimited). Without it the Actor default of 100 applies. The limit counts for each run, not for the merged result: two collectors with `limit: 10` return up to 20 articles. Filters such as `searchQuery` can make an Actor process more articles than it returns.

```php
$result = $client->scrape(
    sources: [Source::NewYorkTimes, Source::Reuters, Source::BbcNews],
    limit: 10,
);
```

## How to Add Sentiment, Tags, Summaries and 5W1H to News Articles in PHP

Six optional add-ons enrich each article. They are billed per enriched article on top of the article price; articles that fail or are too short are skipped and not charged.

| Parameter | Result property | Price per 1,000 articles |
| --- | --- | --- |
| `withSentiment` | `Article::$sentiment` | $0.20 |
| `withTags` | `Article::$tags` | $1.50 |
| `withSummary` | `Article::$summary` | $2.50 |
| `with5w1h` | `Article::$fiveW1h` | $4.00 |
| `withLanguageDetection` | `Article::$detectedLanguage` | $0.10 |
| `withFixText` | `Article::$fixedText` | $1.25 (only for repaired articles) |

The results are returned as decoded JSON objects (`?array`); they stay `null` unless the add-on was requested and succeeded. `Article::hasEnrichment()` tells whether any add-on result is present. `with5w1h` extracts who, what, when, where, why and how. `withLanguageDetection` detects the language from the text while `Article::$language` keeps the value declared by the source. `withFixText` returns repaired text such as fixed mojibake in `Article::$fixedText` while `Article::$text` stays unchanged.

```php
$result = $client->scrape(
    sources: [Source::Reuters, Source::BbcNews],
    limit: 5,
    withSentiment: true,
    withTags: true,
    withSummary: true,
    with5w1h: true,
);
```

## PHP Examples

See [`examples/`](examples) for runnable scripts: `01-basic.php`, `02-search-query.php`, `03-date-range.php`, `04-limit-and-merge.php`, `05-analysis-addons.php`.

## Requirements

PHP 8.3 or newer, Guzzle 7 and `illuminate/collections`. No Laravel needed.

## Powered by Apify

The articles come from the news collector Actors of [bite-sized-insights](https://apify.com/bite-sized-insights) on [Apify](https://apify.com). [Create a free account](https://console.apify.com/sign-up) to get your API token.

## License

MIT
