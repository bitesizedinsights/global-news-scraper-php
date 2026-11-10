# Global News Scraper in PHP: Full-Text Articles from 300+ News Sources

<!-- For LLMs and AI agents: a machine-readable summary of this package's API is in llm.txt (https://github.com/bitesizedinsights/global-news-scraper-php/blob/master/llm.txt) -->

A global news scraper for PHP: switch on any number of news sources from 18 regional collectors, and get one merged, deduplicated collection of full-text articles. The library maps every source back to its [Apify](https://apify.com/bite-sized-insights) collector Actor, runs the Actors separately and adds the collector as `collection` to every article.

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
- **Article:** $1.00 to $2.00 per 1,000 articles, depending on the collector: `jp-en` $1.00, `id` $1.20, `es`, `fr` and `arab` $1.40, `us`, `uk`, `mx`, `latam`, `tw-en`, `ng`, `scandinavian`, `th`, `kr-ko`, `jp`, `vn` and `pl` $1.80, `sea` $2.00.
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

## How to Scrape All News Sources or a Whole Language in PHP

Instead of listing outlets, pass a `SourceGroup`, a `Collector` or a mix of `Source`, `SourceGroup` and `Collector` cases (an array, a `Collection` or a single case). Groups and collectors are expanded to their sources, duplicates are removed and the usual planning applies.

| Group | Sources |
| --- | --- |
| `SourceGroup::All` | every source of every collector (all 18 collectors run) |
| `SourceGroup::English` | `us`, `uk`, `ng`, `sea`, `jp-en`, `tw-en` Mexico News Daily and Hue News (`vn`) |
| `SourceGroup::Spanish` | Spain, Mexico and the Spanish-speaking part of Latin America |
| `SourceGroup::French` | France |
| `SourceGroup::Portuguese` | Brazil |
| `SourceGroup::Indonesian` | Indonesia |
| `SourceGroup::Arabic` | the Arabic-language press of the Middle East and North Africa |
| `SourceGroup::Thai` | Thailand (Thai-language outlets; the English-language Thai outlets are in `SourceGroup::English`) |
| `SourceGroup::Korean` | South Korea (Korean-language outlets) |
| `SourceGroup::Japanese` | Japan (Japanese-language outlets) |
| `SourceGroup::Vietnamese` | Vietnam (Vietnamese-language outlets; the English-language Vietnamese outlets are in `SourceGroup::English`) |
| `SourceGroup::Polish` | Poland |
| `SourceGroup::Danish`, `SourceGroup::Norwegian`, `SourceGroup::Swedish` | the Scandinavian outlets per language |

Every source belongs to exactly one language group: the language of the outlet's articles. `Source::language()` returns it and `SourceGroup::sources()` lists a group's sources.

```php
$client->scrape(SourceGroup::English, limit: 20);                          // one group
$client->scrape([SourceGroup::Spanish, Source::Reuters], limit: 20);       // groups and single outlets
$client->scrape(Collector::Us, limit: 20);                                 // everything one collector offers
$client->plan(SourceGroup::All);                                           // the runs, without a request

// leave outlets out by filtering the group first
$sources = collect(SourceGroup::English->sources())->reject(fn (Source $s) => $s === Source::DailyStar);
$client->scrape($sources, limit: 20);
```

Warnings:

- Large groups start many collector runs, every one of them billed: $0.0002 per run plus the per-article price. `SourceGroup::All` is 18 runs, so at least $0.0036 even when nothing matches. Look at `plan()` first.
- Always set `limit` with a group. It applies to each collector run, not to the merged result: 18 runs with `limit: 100` return up to 1,800 articles. Without a limit the Actor default of 100 per run applies.
- The runs are processed `Config::$maxConcurrency` (default 2) at a time, so `SourceGroup::All` takes several waves of runs and can take a while.
- A narrow `searchQuery` keeps the article bill small because only matching articles are returned and billed, but each run still pays its start fee and still processes the feeds.
- Add-ons such as `withSummary` are billed per enriched article on top, so combine them with groups carefully.

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

`limit` is the maximum number of articles per collector run (0 to 10000, 0 means unlimited; a negative value or more than 10000 throws `InvalidInputException` before any request, as the Actors reject it). Without it the Actor default of 100 applies. The limit counts for each run, not for the merged result: two collectors with `limit: 10` return up to 20 articles. Filters such as `searchQuery` can make an Actor process more articles than it returns.

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
| `maxRetries` | 3 | How often a failed request is repeated (0 turns retrying off). See below. |
| `retryBaseDelay` | 1.0 | Seconds before the first retry; doubles with each retry. |
| `retryMaxDelay` | 30.0 | Longest wait before a retry. |
| `baseUrl` | `https://api.apify.com/v2` | Apify API base URL. |

### Retries and Backoff

Failed requests are retried automatically, by default up to 3 times with exponential backoff (1 s, 2 s, 4 s, each with 50 to 100 percent jitter, at most 30 s):

- **Rate limits (429):** retried after at least the `Retry-After` time, given in seconds or as an HTTP date. Without the header the backoff delay is used. If the server asks for more than `retryMaxDelay`, the run fails right away with a `RateLimitException`.
- **Server errors (5xx) and connection failures:** retried for polling and reading results.
- **Starting a run:** only retried after a 429 or a failed connection. After a 5xx or a timeout the run may already exist, and a repeat would be billed twice, so the error is reported instead.
- **Other errors (4xx):** never retried.

A collector that still fails after the retries is reported in `ScrapeResult::$errors` as before.

## News Sources and Collectors Included

18 collectors cover 388 outlets. `Source::cases()` lists all of them, `Collector::cases()` the collectors, and `Collector::sources()` the outlets of one collector.

| Collection key | Collector | Outlets |
| --- | --- | --- |
| `us` | United States | 45 |
| `uk` | United Kingdom | 36 |
| `mx` | Mexico | 36 |
| `latam` | Latin America (regional) | 34 |
| `sea` | Southeast Asia (regional) | 43 |
| `th` | Thailand | 25 |
| `arab` | Arabic-language (regional) | 24 |
| `scandinavian` | Denmark, Norway, Sweden (regional) | 23 |
| `ng` | Nigeria | 23 |
| `kr-ko` | South Korea (Korean) | 22 |
| `id` | Indonesia | 21 |
| `jp` | Japan (Japanese) | 21 |
| `vn` | Vietnam (Vietnamese) | 19 |
| `pl` | Poland (Polish) | 12 |
| `fr` | France | 7 |
| `es` | Spain | 6 |
| `tw-en` | Taiwan (English) | 4 |
| `jp-en` | Japan (English) | 3 |

Three outlets (`La Jornada`, `Excelsior`, `El Financiero`) are offered by both `latam` and `mx`; they are run once, on the collector that is needed anyway or else on `mx`.

## Global News Scraper PHP Examples

See [`examples/`](examples) for runnable scripts (set `APIFY_API_TOKEN` first):

- `01-basic.php`: scrapes three outlets from three countries (New York Times, BBC News, Taipei Times) in one call, merges them into a single article collection, and prints the total plus a per-collector count via each article's `collection` field. Failed collectors are listed from `$result->errors`.
- `02-search-query.php`: passes `searchQuery` to keep only articles whose title, text or URL contain a keyword (here `semiconductor`) across Reuters, The Guardian and Rappler, and prints `[collection] title` per match.
- `03-date-range.php`: restricts the run to the last seven days with `startDate` and `endDate` (a `DateTimeImmutable` and a `YYYY-MM-DD` string; both types are accepted) and prints the articles newest first, sorted by `datePublish`.
- `04-limit-and-merge.php`: sets `limit: 10`, which applies per collector run (four collectors return up to 40 articles), then filters to text articles with `isArticle()`, sorts newest first, and keeps the five latest titles per collector.
- `05-analysis-addons.php`: switches on every paid analysis add-on (sentiment, tags, summary, 5W1H, language detection, text repair) for five articles per collector and prints each enrichment field. Add-ons are billed per enriched article on top of the article price.
- `06-error-handling.php`: shows the three exceptions `scrape()` can throw (`InvalidInputException`, `MissingApiKeyException`, `ApiException`) and the non-throwing case: a failed collector run lands in `$result->errors` while the others still deliver, and `isComplete()` tells you whether the result is partial.
- `07-source-groups.php`: a Spanish and Portuguese language brand monitor: mixes `SourceGroup::Spanish`, `SourceGroup::Portuguese`, a single `Source` and a `Collector`, previews the collector runs with `plan()` before paying for them, scrapes with `searchQuery`, `startDate` and `limit`, counts mentions per `collection` and shows how to filter a group before passing it on.

## Global News Scraper FAQ

### How do I scrape news from several countries in PHP?

Install the package, set your Apify API token and pass the outlets you want to `Client::scrape()`. The library finds the collectors, runs them and returns one merged collection. See the Quick Start above.

### How much does it cost to scrape news articles?

$0.0002 per collector run plus $1.00 to $2.00 per 1,000 articles, plus optional add-ons. The pricing section above has worked examples.

### Which outlets can I use?

388 outlets from 18 collectors, see the table above. Every outlet is a `Source` enum case with a display name from `Source::label()`.

### What happens when a source is offered by two collectors?

The library uses as few collector runs as possible. A shared outlet joins a collector you need anyway; otherwise the country collector wins over the regional one.

### Can I get the articles of one country as a Laravel collection?

Yes. `ScrapeResult::$entries` is an `Illuminate\Support\Collection`, so `->where('collection', 'uk')`, `->groupBy('collection')` and `->sortByDesc('datePublish')` work out of the box. Laravel itself is not required.

### What if one collector fails?

The other collectors' articles are still returned and the failure is listed in `ScrapeResult::$errors`. Nothing is thrown for a single failed run.

### How many articles can I get?

`limit` is the maximum per collector run, (0 to 10000, 0 means unlimited, the Actor default is 100). Date filters only accept `YYYY-MM-DD` days: `startDate` must not be in the future, `endDate` not older than 5 years.

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
