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

## Sample Output: Merged News Articles with Collection

Every entry is an `Article` object. This is one entry (values from a US collector item):

```
url:          https://www.nytimes.com/2026/02/15/us/example-headline.html
collection:   us
title:        Example article headline
authors:      ["Author Name"]
datePublish:  2026-02-15T10:30:00
description:  Brief summary of the article content.
text:         Full article text extracted from the page...
language:     en
imageUrl:     https://static01.nyt.com/images/example.jpg
sourceDomain: www.nytimes.com
type:         ArticleType::Article
```

Video and audio pages (`type` is `video` or `audio`) have little or no text: use `Article::isArticle()` or `->where('type', ArticleType::Article)` to keep articles only.

## Global News Scraper Pricing: Cost per Article

The package is free. You pay Apify for the collector runs, on a pay-per-event basis (prices of the Free plan, paid plans get lower rates):

- **Run start:** $0.0002 per collector run.
- **Article:** $1.00 to $2.00 per 1,000 articles, depending on the collector: `jp-en` $1.00, `id` $1.20, `es`, `fr` and `arab` $1.40, `us`, `uk`, `mx`, `latam`, `tw-en`, `ng` and `scandinavian` $1.80, `sea` $2.00.
- **Add-ons (optional, per enriched article):** sentiment $0.20, tags $1.50, summary $2.50, 5W1H $4.00, language detection $0.10, text fixing $1.25 per 1,000 articles. Add-ons are the same for every collector.

Examples:

- 100 articles from the `us` collector: $0.0002 + 100 × $0.0018 = $0.1802.
- 50 articles each from `us`, `uk` and `jp-en` (three runs): 3 × $0.0002 + 50 × ($0.0018 + $0.0018 + $0.0010) = $0.2306. With sentiment and summary on all 150 articles: + 150 × ($0.0002 + $0.0025) = $0.6356 in total.

Check the Actor pages on [Apify](https://apify.com/bite-sized-insights) for current prices. [Run the collectors on Apify](https://console.apify.com/sign-up) with the free monthly credit.

## Use Cases for a Global News Scraper

- **Media monitoring and PR:** follow a brand, a topic or a person across the press of several countries with `searchQuery` and one merged result.
- **Market and competitor intelligence:** collect business and tech coverage from many outlets and group it by `collection`.
- **NLP and AI datasets:** build multilingual full-text corpora with language detection, summaries and tags.
- **News apps and aggregators:** feed a Laravel or plain PHP application with deduplicated articles from the outlets you pick.

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

## Error Handling for Partial News Results in PHP

A failing collector run, for example a rate limit, a server error or a failed Actor run, does not throw. It is returned in `ScrapeResult::$errors` as a `CollectorError` (collector and message) while the articles of all other collectors are still returned. `hasErrors()` and `isComplete()` summarize the outcome.

Invalid input throws `InvalidInputException` (an `InvalidArgumentException`) before any request is sent, and a missing API key throws `MissingApiKeyException`.

```php
$result = $client->scrape([Source::Reuters, Source::BbcNews]);

foreach ($result->errors as $error) {
    echo "{$error->collector->value} failed: {$error->message}\n";
}
```

## Configure Concurrency and Timeouts for the PHP News Scraper

```php
use GlobalNewsScraper\Config;

$client = (new Client(new Config(maxConcurrency: 3, timeout: 1200)))->setApifyKey($apiToken);
```

| Option | Default | Meaning |
| --- | --- | --- |
| `maxConcurrency` | 2 | Collector runs active at the same time. Every run already fetches several articles in parallel on the shared news backend, so the default of 2 keeps the load moderate. Raise it for faster multi-country runs. |
| `timeout` | 900 | Seconds to wait for each collector run to finish. |
| `requestTimeout` | 90 | Timeout in seconds of a single HTTP request. |
| `baseUrl` | `https://api.apify.com/v2` | Apify API base URL. |

## News Sources and Collectors Included

13 collectors cover 302 outlets. `Source::cases()` lists all of them, `Collector::cases()` the collectors, and `Collector::sources()` the outlets of one collector.

| Collection key | Collector | Outlets |
| --- | --- | --- |
| `us` | United States | 45 |
| `uk` | United Kingdom | 36 |
| `mx` | Mexico | 36 |
| `latam` | Latin America (regional) | 34 |
| `sea` | Southeast Asia (regional) | 43 |
| `arab` | Arabic-language (regional) | 24 |
| `scandinavian` | Denmark, Norway, Sweden (regional) | 23 |
| `ng` | Nigeria | 23 |
| `id` | Indonesia | 21 |
| `fr` | France | 7 |
| `es` | Spain | 6 |
| `tw-en` | Taiwan (English) | 4 |
| `jp-en` | Japan (English) | 3 |

Three outlets (`La Jornada`, `Excelsior`, `El Financiero`) are offered by both `latam` and `mx`; they are run once, on the collector that is needed anyway or else on `mx`.

## Global News Scraper PHP Examples

See [`examples/`](examples) for runnable scripts: `01-basic.php`, `02-search-query.php`, `03-date-range.php`, `04-limit-and-merge.php`, `05-analysis-addons.php`, `06-error-handling.php`.

## Global News Scraper FAQ

### How do I scrape news from several countries in PHP?

Install the package, set your Apify API token and pass the outlets you want to `Client::scrape()`. The library finds the collectors, runs them and returns one merged collection. See the Quick Start above.

### How much does it cost to scrape news articles?

$0.0002 per collector run plus $1.00 to $2.00 per 1,000 articles, plus optional add-ons. The pricing section above has worked examples.

### Which outlets can I use?

302 outlets from 13 collectors, see the table above. Every outlet is a `Source` enum case with a display name from `Source::label()`.

### What happens when a source is offered by two collectors?

The library uses as few collector runs as possible. A shared outlet joins a collector you need anyway; otherwise the country collector wins over the regional one.

### Can I get the articles of one country as a Laravel collection?

Yes. `ScrapeResult::$entries` is an `Illuminate\Support\Collection`, so `->where('collection', 'uk')`, `->groupBy('collection')` and `->sortByDesc('datePublish')` work out of the box. Laravel itself is not required.

### What if one collector fails?

The other collectors' articles are still returned and the failure is listed in `ScrapeResult::$errors`. Nothing is thrown for a single failed run.

### How many articles can I get?

`limit` is the maximum per collector run, up to 10000 (0 means unlimited, the Actor default is 100). Date filters only accept `YYYY-MM-DD` days: `startDate` must not be in the future, `endDate` not older than 5 years.

### Does it work without Laravel?

Yes. The package needs PHP 8.3, Guzzle and `illuminate/collections`, which is a standalone package.

## Requirements

PHP 8.3 or newer, Guzzle 7 and `illuminate/collections`. No Laravel needed.

## Powered by Apify

The articles come from the news collector Actors of [bite-sized-insights](https://apify.com/bite-sized-insights) on [Apify](https://apify.com), which return titles, authors, publication dates and full text from regional news outlets. [Create a free account](https://console.apify.com/sign-up) to get your API token.

## Contributing and Tests

Run `composer check` for code style, static analysis and tests. See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT
