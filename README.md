# Global News Scraper in PHP: Full-Text Articles from 1,000+ News Sources

<!-- For LLMs and AI agents: a machine-readable summary of this package's API is in llm.txt (https://github.com/bitesizedinsights/global-news-scraper-php/blob/master/llm.txt) -->

A global news scraper for PHP: switch on any number of news sources from 45+ regional and topic collectors, and get one merged, deduplicated collection of full-text articles. The library maps every source back to its [Apify](https://apify.com/bite-sized-insights) collector Actor, runs the Actors separately and adds the collector as `collection` to every article.

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

The package is free. You pay Apify for the collector runs, on a pay-per-event basis (prices of the Free plan; the article and add-on prices of paid plans are lower: Bronze 5%, Silver 15%, Gold, Platinum and Diamond 35%):

- **Run start:** $0.0002 per collector run.
- **Article:** $1.00 to $2.00 per 1,000 articles, depending on the collector: `jp-en` $1.00, `id` and `id-en` $1.20, `es`, `fr` and `arab` $1.40, `us`, `uk`, `mx`, `latam`, `tw-en`, `ng`, `scandinavian`, `th`, `kr-ko`, `jp`, `vn`, `pl`, `tr`, `it`, `de`, `au`, `br`, `ca`, `ch`, `il`, `il-en`, `in`, `in-en`, `kr-en`, `nl`, `ph`, `tw`, `us-finance`, `us-tech`, `uk-finance`, `uk-tech`, `in-finance`, `defense`, `energy-climate`, `startups-vc`, `supply-chain`, `biopharma`, `insurance`, `marketing` and `sports-business` $1.80, `sea` $2.00.
- **Add-ons (optional, per enriched article):** sentiment $0.20, tags $1.50, summary $2.50, 5W1H $4.00, language detection $0.10, text fixing $1.25 per 1,000 articles. Add-ons are the same for every collector.

Examples:

- 100 articles from the `us` collector: $0.0002 + 100 × $0.0018 = $0.1802.
- 50 articles each from `us`, `uk` and `jp-en` (three runs): 3 × $0.0002 + 50 × ($0.0018 + $0.0018 + $0.0010) = $0.2306. With sentiment and summary on all 150 articles: + 150 × ($0.0002 + $0.0025) = $0.6356 in total.

Free-plan users get 5 runs in total, each returning at most 10 articles; test runs in the Apify Console have the same cap.

### Estimate the Cost of a Run Plan in PHP

`CostEstimator::estimate()` calculates the upper bound of a plan (a `RunPlan` or the list of `plan()`) without any request:

```php
use GlobalNewsScraper\CostEstimator;
use GlobalNewsScraper\PricingTier;

$plan = $client->plan([Source::NewYorkTimes, Source::BbcNews, Source::NikkeiAsia]);

$estimate = CostEstimator::estimate($plan, limit: 50, withSentiment: true, withSummary: true);

$estimate->runs;          // 3
$estimate->startFeesUsd;  // 0.0006
$estimate->maxArticles;   // 150
$estimate->maxTotalUsd;   // 0.6356

CostEstimator::estimate($plan, limit: 50, tier: PricingTier::Gold)->maxTotalUsd; // 0.1501
CostEstimator::estimate($plan, limit: 0)->maxTotalUsd;                          // null: unlimited, no upper bound
```

| `CostEstimate` property | Meaning |
| --- | --- |
| `tier`, `runs` | Tier the prices are for (default `PricingTier::Free`) and number of collector runs; every plan item counts as one |
| `startFeesUsd` | Start fees of all runs, due even without any article |
| `maxArticles` | Runs times limit |
| `maxArticlesUsd`, `maxAddOnsUsd`, `maxTotalUsd` | Article price, add-on price and total (with start fees) for `maxArticles` |
| `isBounded()` | False with `limit: 0` (unlimited): then the four `max...` values are null (an empty plan always costs 0) |

`limit` and the `with...` arguments work like those of `scrape()`: without `limit` the Actor default of 100 per run is used, and an invalid limit throws `InvalidInputException`.

**It is an estimate, not a quote.** The prices are built into this library version and can differ from the current ones on Apify. Pick the tier of your Apify account yourself (`Free`, `Bronze`, `Silver`, `Gold`, `Platinum`, `Diamond`); the library does not look it up. The upper bound assumes that every run returns `limit` articles and every requested add-on succeeds on each of them; add-ons are only billed where they succeed, text fixing only for repaired articles, and 5W1H skips texts under 50 characters. The free-plan cap of 10 articles per run is not applied. Credits, further discounts and other charges of your account are not covered, and neither are runs you repeat.

Check the Actor pages on [Apify](https://apify.com/bite-sized-insights) for current prices. [Run the collectors on Apify](https://console.apify.com/sign-up) with the free monthly credit.

## Use Cases for a Global News Scraper

- **Media monitoring and PR:** follow a brand, a topic or a person across the press of several countries with `searchQuery` and one merged result.
- **Market and competitor intelligence:** collect business and tech coverage from many outlets and group it by `collection`.
- **NLP and AI datasets:** build multilingual full-text corpora with language detection, summaries and tags.
- **News apps and aggregators:** feed a Laravel or plain PHP application with deduplicated articles from the outlets you pick.

## How to Scrape Specific News Sources

`Client::scrape()` takes the sources you want as `Source` enum cases (an array or a Laravel-style `Collection`). There is one case per outlet, for example `Source::NewYorkTimes`, `Source::BbcNews` or `Source::Rappler`; `Source::label()` returns the display name.

What happens behind the scenes:

- Each source is mapped back to the collector Actor that offers it (`Source::collectors()`). `Client::plan()` shows the runs without sending a request.
- The fewest collectors that cover all your sources are used. A source offered by several collectors (for example `Source::LaJornada` in `latam` and `mx`) goes to a collector that is needed anyway, otherwise to the country collector rather than the regional one.
- Every collector runs once, separately, with only its sources switched on. Up to `Config::$maxConcurrency` runs (default 2) are active at the same time.
- The articles are merged and deduplicated by URL, ignoring `http`/`https`, `www.`, default ports, trailing slashes, in-page fragments, query parameter order and tracking parameters (`utm_*`, `fbclid`, `gclid` and similar); the first article is kept with its URL unchanged. Each `Article` has a `collection` property with the collector key (`us`, `uk`, `jp-en`, ...).
- `scrape()` returns a `ScrapeResult`: `entries` is an `Illuminate\Support\Collection` of `Article` objects, `errors` lists the collectors whose run failed. A failed collector never hides the articles of the others.

```php
$result->entries
    ->where('collection', 'uk')
    ->sortByDesc('datePublish')
    ->pluck('title');

$result->entries->groupBy('collection')->map->count();
```

### How to Find a News Source by Name

When the outlet name comes from a user, a config file or a database, look the `Source` case up:

```php
Source::fromLabel('the new york times');   // Source::NewYorkTimes
Source::fromLabel('The Guardian');         // null: two sources have this name
Source::search('the guardian');            // both of them first, then other names and keys containing the text
Source::search('bbc');                     // the sources with "bbc" in their name or key

foreach (Source::search('guardian') as $source) {
    echo "{$source->value}: {$source->label()} (".implode(', ', array_column($source->collectors(), 'value')).")\n";
}
```

- `fromLabel()` matches the whole display name (`Source::label()`), ignoring case, leading and trailing white space and repeated spaces. It returns null when nothing matches **or when several sources match**: a few display names belong to two sources. It never guesses. If names differ in case only, the exact spelling wins.
- `search()` returns all sources whose display name or key (the enum value, e.g. `bbc_news`) contains the text, ignoring case: sources named exactly like the query first, then the rest in the order of `Source::cases()`. A blank query returns an empty list. The text is taken literally, not as a pattern.
- Neither folds accents (`Excelsior` does not find `Excélsior`) or corrects typos. Use the key with `Source::tryFrom()` when you need a stable identifier.

## How to Scrape All News Sources or a Whole Language

Instead of listing outlets, pass a `SourceGroup`, a `Collector` or a mix of `Source`, `SourceGroup` and `Collector` cases (an array, a `Collection` or a single case). Groups and collectors are expanded to their sources, duplicates are removed and the usual planning applies.

| Group | Sources |
| --- | --- |
| `SourceGroup::All` | every source of every collector (45+ collector runs: `id-en` has no outlet of its own that `sea` does not already cover) |
| `SourceGroup::English` | `us`, `uk`, `ng`, `sea`, `jp-en`, `tw-en`, `au`, `ca`, `il-en`, `in-en`, `kr-en`, `id-en`, `us-finance`, `us-tech`, `uk-finance`, `uk-tech`, `in-finance`, `defense`, `energy-climate`, `startups-vc`, `supply-chain`, `biopharma`, `insurance`, `marketing` and `sports-business`, plus the English-language outlets of `mx`, `th`, `vn` and `ph` |
| `SourceGroup::Spanish` | Spain, Mexico and the Spanish-speaking part of Latin America (`es`, `mx`, `latam`) |
| `SourceGroup::French` | France and French-speaking Switzerland (`fr`, `ch`) |
| `SourceGroup::Portuguese` | Brazil (`br`, `latam`) |
| `SourceGroup::Indonesian` | Indonesia |
| `SourceGroup::Arabic` | the Arabic-language press of the Middle East and North Africa |
| `SourceGroup::Thai` | Thailand (Thai-language outlets; the English-language Thai outlets are in `SourceGroup::English`) |
| `SourceGroup::Korean` | South Korea (Korean-language outlets) |
| `SourceGroup::Japanese` | Japan (Japanese-language outlets) |
| `SourceGroup::Vietnamese` | Vietnam (Vietnamese-language outlets; the English-language Vietnamese outlets are in `SourceGroup::English`) |
| `SourceGroup::Polish` | Poland |
| `SourceGroup::Turkish` | Turkey |
| `SourceGroup::Italian` | Italy and Italian-speaking Switzerland (`it`, `ch`) |
| `SourceGroup::German` | Germany and German-speaking Switzerland (`de`, `ch`) |
| `SourceGroup::Dutch` | Netherlands (`nl`) |
| `SourceGroup::Hebrew` | Israel (Hebrew-language outlets, `il`) |
| `SourceGroup::Hindi` | India (Hindi-language outlets, `in`) |
| `SourceGroup::Chinese` | Taiwan (Traditional Chinese-language outlets, `tw`) |
| `SourceGroup::Tagalog`, `SourceGroup::Ilocano`, `SourceGroup::Hiligaynon`, `SourceGroup::Bikol` | the Philippine-language outlets per language (`ph`) |
| `SourceGroup::Danish`, `SourceGroup::Norwegian`, `SourceGroup::Swedish` | the Scandinavian outlets per language |

Every source belongs to exactly one language group: the language of the outlet's articles. `Source::language()` returns it and `SourceGroup::sources()` lists a group's sources.

```php
$client->scrape(SourceGroup::English, limit: 20);                          // one group
$client->scrape([SourceGroup::Spanish, Source::WashingtonPost], limit: 20);       // groups and single outlets
$client->scrape(Collector::Us, limit: 20);                                 // everything one collector offers
$client->plan(SourceGroup::All);                                           // the runs, without a request

// leave outlets out by filtering the group first
$sources = collect(SourceGroup::English->sources())->reject(fn (Source $s) => $s === Source::DailyStar);
$client->scrape($sources, limit: 20);
```

Warnings:

- Large groups start many collector runs, every one of them billed: $0.0002 per run plus the per-article price. `SourceGroup::All` is 45+ runs, so at least $0.009 even when nothing matches. Look at `plan()` first.
- Always set `limit` with a group. It applies to each collector run, not to the merged result: 45+ runs with `limit: 100` return up to 4,500+ articles. Without a limit the Actor default of 100 per run applies.
- The runs are processed `Config::$maxConcurrency` (default 2) at a time, so `SourceGroup::All` takes several waves of runs and can take a while.
- A narrow `searchQuery` keeps the article bill small because only matching articles are returned and billed, but each run still pays its start fee and still processes the feeds.
- Add-ons such as `withSummary` are billed per enriched article on top, so combine them with groups carefully.

## How to Search News Articles by Keyword

Pass `searchQuery` to keep only articles whose title, text or URL contain the keyword (1 to 100 characters). It is sent to every collector run.

```php
$result = $client->scrape(
    sources: [Source::WashingtonPost, Source::TheGuardian, Source::Rappler],
    searchQuery: 'semiconductor',
);
```

Keyword filtering can make a collector process more articles than it returns.

## How to Filter News by Date Range

`startDate` and `endDate` keep articles published on or after / on or before a day. Pass `YYYY-MM-DD` strings or `DateTimeInterface` objects. `startDate` must not be in the future, `endDate` not older than 5 years and not before `startDate`; invalid dates throw `InvalidInputException` before any request is sent.

```php
$result = $client->scrape(
    sources: [Source::WashingtonPost, Source::BbcNews],
    startDate: new DateTimeImmutable('-7 days'),
    endDate: date('Y-m-d'),
);
```

### Publication Date as a PHP Date

`Article::$datePublish` is the Actor's string: ISO 8601 as the article page states it, often without a time zone (`2026-02-15T10:30:00`), sometimes with one (`2026-02-15T10:30:00+09:00`), or null. `publishedAt()` returns it as a `?DateTimeImmutable`:

```php
$article->publishedAt();                                  // offset kept; without one the time is read as UTC
$article->publishedAt(new DateTimeZone('Asia/Tokyo'));    // zone for values without an offset only
```

- It accepts only complete ISO 8601 dates: `YYYY-MM-DD`, optionally followed by `T` or a space and `HH:MM[:SS[.ffffff]]`, optionally followed by `Z` or an offset (`+09:00`, `+0900`, `+09`, -14:00 to +14:00). A date without a time is midnight. Digits after the sixth decimal of a second are cut off.
- Everything else gives null instead of a guess: empty strings, relative phrases (`yesterday`, `2 hours ago`), free text, ambiguous forms (`02/03/2026`), time zone abbreviations (`EST`) and impossible values (`2026-02-30`, `25:00`).
- A value without an offset does not say which zone it means, so sorting or filtering across outlets can be off by up to a day.

## How to Limit the Number of News Articles in PHP

`limit` is the maximum number of articles per collector run (0 to 10000, 0 means unlimited; a negative value or more than 10000 throws `InvalidInputException` before any request, as the Actors reject it). Without it the Actor default of 100 applies. The limit counts for each run, not for the merged result: two collectors with `limit: 10` return up to 20 articles. Filters such as `searchQuery` can make an Actor process more articles than it returns.

```php
$result = $client->scrape(
    sources: [Source::NewYorkTimes, Source::WashingtonPost, Source::BbcNews],
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

The array properties hold the decoded JSON objects as the Actor returns them (`?array`); they stay `null` unless the add-on was requested and succeeded. `Article::hasEnrichment()` tells whether any add-on result is present. `with5w1h` extracts who, what, when, where, why and how (only for texts of at least 50 characters). `withLanguageDetection` detects the language from the text while `Article::$language` keeps the value declared by the source. `withFixText` returns the repaired text, such as fixed mojibake, in `Article::$fixedText` while `Article::$text` stays unchanged; the result is also present when nothing needed repair (`changed` is false, not billed).

### Typed Add-on Results

For each array property there is an accessor returning a typed object (namespace `GlobalNewsScraper\DTO\Enrichment`):

| Accessor | Object and properties |
| --- | --- |
| `sentimentResult()` | `Sentiment`: `label` (`SentimentLabel::Positive`, `Neutral` or `Negative`), `confidence`, `scores` (`SentimentScores`: `positive`, `neutral`, `negative`, `compound`), `method` |
| `tagsResult()` | `Tags`: `tags` (list of strings, most relevant first), `model` |
| `summaryResult()` | `Summary`: `text`, `originalLength`, `summaryLength`, `compressionRatio`, `model` |
| `fiveW1hResult()` | `FiveW1h`: `who`, `what`, `when`, `where`, `why`, `how` (each a `FiveW1hAnswer` with `answer` and `confidence`), `model` |
| `detectedLanguageResult()` | `DetectedLanguage`: `language`, `confidence`, `candidates` (list of `LanguageProbability` with `language` and `probability`), `method` |
| `fixedTextResult()` | `FixedText`: `text`, `changed`, `method` |

An accessor returns null when there is no result or the array does not have the expected shape. Optional values that are missing or of another type are null. Nothing throws for an unknown shape, and the array property keeps the raw data (an add-on field that is not a JSON object or array at all is read as null).

```php
$result = $client->scrape(
    sources: [Source::WashingtonPost, Source::BbcNews],
    limit: 5,
    withSentiment: true,
    withTags: true,
    withSummary: true,
    with5w1h: true,
);

foreach ($result->entries as $article) {
    echo $article->sentimentResult()?->label->value, PHP_EOL;
    echo implode(', ', $article->tagsResult()?->tags ?? []), PHP_EOL;
    echo $article->summaryResult()?->text, PHP_EOL;
    echo $article->fiveW1hResult()?->who?->answer, PHP_EOL;
}
```

## How to Count News Articles per Collector and Domain

`Results` counts the articles of a `ScrapeResult`, from `scrape()` as well as from `results()`:

```php
use GlobalNewsScraper\Results;

$sources = [Source::NewYorkTimes, Source::BbcNews, Source::TaipeiTimes];
$plan = $client->plan($sources);
$result = $client->scrape($sources, limit: 20);

Results::countsByCollector($result, $plan); // every planned collector, also with 0, e.g. ['us' => 20, 'uk' => 20, 'tw-en' => 0]
Results::countsByDomain($result);           // e.g. ['nytimes.com' => 20, 'bbc.com' => 17, 'bbc.co.uk' => 3]
```

| Method | Returns |
| --- | --- |
| `countsByCollector($result, $plan = [])` | Articles per collector key. With a plan: every planned collector in plan order, also with 0, then any other collector that has articles. Without one: only collectors with articles. |
| `countsByDomain($result)` | Articles per host of the article URL (`Article::$sourceDomain`), lower-cased and without `www.`; other subdomains stay separate and articles without a host are left out. |

**There are no counts or matches per `Source`.** An article names its collector and the host of its URL, not the source it came from. A host does not identify a source: several sources of one collector can share a host (for example language editions or sections of one publisher), one outlet can publish on several hosts, a feed can link to another site, and the Actors do not publish which hosts belong to a source. The plan does not settle it either, not even for a run with a single planned source: a collector can offer sources this library version does not know yet and cannot switch off. Filter `countsByDomain()` yourself if you know the hosts of your outlets.

### How to Find Collectors Without Results

The index methods match the result against the collector runs, the `RunPlan` list of `plan()` for the sources you passed to `scrape()` or `start()`. Indexes are 1-based positions in that list:

```php
Results::inputCount($plan);                  // number of collector runs, e.g. 3
Results::missingIndexes($result, $plan);     // positions of the collectors without any article, e.g. [3]
Results::isComplete($result, $plan);         // true when every planned collector has an article
Results::failedCollectors($result, $plan);   // list<Collector> with a CollectorError
Results::emptyCollectors($result, $plan);    // list<Collector> with neither an article nor an error

foreach (Results::missingIndexes($result, $plan) as $index) {
    $sources = $plan[$index - 1]->sources;   // none of them has an article under this collector
}
```

`Results::isComplete()` is not `ScrapeResult::isComplete()`: the latter only says that there is no error. A collector counts as present with a single article, so neither method says that every source or every matching article arrived.

With `start()` and `results()`, create the plan when you start and store it next to the batch (`json_encode($plan)`, restore each item with `RunPlan::fromArray()`): a later library version may plan the same sources differently, and a `RunBatch` keeps neither the sources nor the plan order of collectors that failed to start. `failedCollectors()` then includes unfinished and unreadable runs (check `$batch->isFinished()`), and `emptyCollectors()` is only meaningful when `results()` got all runs of the plan: a run you left out looks empty.

### How to Run Failed News Collectors Again

`Results::unprocessed()` returns the sources of the collectors with a `CollectorError`, ready for another call:

```php
$retry = Results::unprocessed($result, $plan);          // after scrape()
$retry = Results::unprocessed($result, $plan, $batch);  // after results(), with the batch returned by wait()

if ($retry !== []) {                                    // scrape() and start() reject an empty list
    $more = $client->scrape($retry, limit: 20);
}
```

- Collectors with neither an article nor an error are not included: their runs may well have succeeded, and every new run is billed.
- With the batch, collectors with a run that is unfinished or successful are left out: starting an unfinished one again would run it twice, and a successful run that could not be read only needs another `results()` call. **Without the batch their sources are returned too**, so always pass it after `results()`.
- An error does not prove that nothing ran or was billed. Look at `$error->runId` first: `results($error->runId)` reads a run that succeeded after all, without a new run. A start that ended with a server error or a timeout may have created a run although there is no ID: check Apify Console. After a spending limit, raise it before retrying.
- `plan($retry)` may pick other collectors than before for sources that several collectors offer, so count a retry against its own plan. Nothing is deduplicated across two results: compare the URLs yourself when you combine them.

**What the counts mean:**

- They count the merged result. Of duplicates only the article of the earlier run is kept, so a collector can show fewer articles (even 0) than its run returned and billed.
- `limit` applies to each run. A count equal to your limit suggests there is more; a lower count does not show that there is nothing more, and it does not say why: filters, little recent news, the 10 article cap of free-plan and test runs, a maximum charge per run on Apify or the run's time limit all lead to fewer articles, and such a run can still succeed without a `CollectorError`. A successful run whose free-plan runs are used up has no articles and no error either. With `start()` and `wait()`, `Run::$statusMessage` may say more.

## Error Handling for Partial News Results

A failing collector run, for example a rate limit, a server error or a failed Actor run, does not throw. It is returned in `ScrapeResult::$errors` as a `CollectorError` (collector, message and, when known, the Apify run ID in `runId`, which can be passed to `Client::abort()`, and, for a failed run, the Actor's own reason in `statusMessage`) while the articles of all other collectors are still returned. `hasErrors()` and `isComplete()` summarize the outcome.

Invalid input throws `InvalidInputException` (an `InvalidArgumentException`) before any request is sent, and a missing API key throws `MissingApiKeyException`.

```php
$result = $client->scrape([Source::WashingtonPost, Source::BbcNews]);

foreach ($result->errors as $error) {
    echo "{$error->collector->value} failed: {$error->message}\n";
}
```

## How to Start News Scraper Runs and Collect the Results Later (Async API)

`scrape()` blocks until every collector run has finished. To start the runs, keep their Apify run IDs and read the articles later (a queue job, a cron job, a web request), use `start()`, `wait()` and `results()`:

```php
use GlobalNewsScraper\DTO\RunBatch;

// Process 1: start the runs (same arguments as scrape()) and store the handles at once
$batch = $client->start([Source::NewYorkTimes, Source::BbcNews, Source::TaipeiTimes], limit: 20);
file_put_contents('runs.json', json_encode($batch));

foreach ($batch->errors as $error) {
    echo "{$error->collector->value} was not started: {$error->message}\n";
}

// Process 2, any time later
$batch = RunBatch::fromArray(json_decode(file_get_contents('runs.json'), true));
$batch = $client->wait($batch, timeout: 300);

if ($batch->isFinished()) {
    $result = $client->results($batch); // a ScrapeResult, merged and deduplicated like the one of scrape()
}
```

| Method | What it does |
| --- | --- |
| `start(...): RunBatch` | Starts the runs of `plan()` and returns at once. `$batch->runs` holds one `Run` handle per started run (`collector`, `id`, `status`, `defaultDatasetId`, `startedAt`, `finishedAt`, `statusMessage`), `$batch->errors` one `CollectorError` per collector without a handle. `$batch->runIds()` lists the IDs. |
| `getRun(Run\|string $run): Run` | Looks one run up once, without waiting. |
| `wait($runs, ?int $timeout = null): RunBatch` | Waits until all runs are final or the timeout expires and returns the updated handles. |
| `results($runs): ScrapeResult` | Reads the articles of the successful runs. Does not wait. |
| `abort(Run\|string $run): void` | Stops one run. |

`wait()` and `results()` take a `RunBatch`, one `Run` or run ID, or an array or collection of handles and IDs; a run given twice is used once.

- **Starting:** a failed start does not throw and never costs you the IDs of the runs that were started. The library never repeats a start that may have reached Apify (it only repeats one after a 429 or a connection failure that shows the request was not sent). After a server error, a timeout or an unreadable answer the run may exist although no handle came back: check Apify Console before you start that collector again. To retry, pass the sources of the failed collectors (see `plan()`) to `start()`.
- **Spending limit:** after the first HTTP 402 no further run is started; the other collectors are listed in `$batch->errors` (`Not started: ...`) next to the handles of the started runs. `start()` throws `SpendingLimitException` only when not a single run was started.
- **Concurrency:** `Config::$maxConcurrency` limits how many requests `start()`, `wait()` and `results()` send at the same time. It does not limit the runs: everything `start()` started executes at once on Apify and on the shared news backend. Start big selections in portions, or use `scrape()`.
- **Run time limit:** a started run gets the same Apify time limit as in `scrape()`, by default `Config::$timeout` (900 s). Change it with `runTimeout` (see [Run Timeout](#run-timeout)).
- **Waiting:** `timeout` is for all runs together, in seconds (null = `Config::$timeout`, 0 = look each unfinished run up once). `wait()` never aborts a run and does not throw when a run fails or the time is up: check `$batch->isFinished()` and `$batch->isSuccessful()`, and call it again if needed. A run that cannot be read keeps its last known state and gets the reason in `$run->lookupError`; it is not asked again in that call, while the others are still waited for. After the timeout each run not looked up yet is looked up once without waiting, so the call can take somewhat longer than `timeout`.
- **Results:** only successful runs have articles. A failed, aborted or timed-out run, an unfinished run (`Actor run is not finished (status RUNNING): ...`) and a run or dataset that cannot be read become a `CollectorError` with the run ID, next to the start errors of the batch, so `isComplete()` is only true when nothing is missing. `results()` never aborts a run and can be called again. Like `scrape()`, it throws `SpendingLimitException` only when HTTP 402 stopped the reading and no article was read.
- **Bare run IDs:** if you stored only the ID, `getRun()`, `wait()` and `results()` identify the collector with an extra request for the run's Actor, and throw `ApiException` for an unknown run (HTTP 404, code 404) and for a run that does not belong to a collector Actor of this library. Storing the handles (`json_encode($batch)`) avoids that request; the collector of a stored handle is taken as it is, so only restore handles you saved yourself.
- **Invalid IDs:** a run ID with characters no Apify ID has (such as `/`, `.`, `?` or `#`) throws `InvalidInputException` before any request; `wait()` and `results()` check all IDs first.

## Store and Restore News Articles

`Article`, `ScrapeResult`, `CollectorError`, `RunPlan`, `Run` and `RunBatch` implement `JsonSerializable` and have `toArray()` and `fromArray()`. An article uses the Actor's field names (`article_title`, `date_publish`, `source_domain`, ...) plus `collection`, so `fromArray()` restores an equal object. `Article::$error` is deprecated: the Actors reserve the field and always send null (failed articles are left out of the dataset, failed runs are in `ScrapeResult::$errors`); it stays in `toArray()` for compatibility.

```php
$json = json_encode($result);                            // database column, cache, queue payload
$restored = ScrapeResult::fromArray(json_decode($json, true));
```

## Filter News Results and Export Them to JSON, CSV or JSON Lines

The filters of a `ScrapeResult` work on what was already fetched (and billed). Each returns new objects with their own collections, keeps the errors and leaves the original unchanged:

```php
$articles = $result->onlyArticles();               // without video and audio pages
$recent = $result->since('2026-02-15');            // published at or after that moment
$all = $result->since(new DateTimeImmutable('-24 hours'), keepUndated: true);

foreach ($result->groupByCollection() as $key => $group) {
    echo "{$key}: {$group->entries->count()} articles, {$group->errors->count()} errors\n";
}
```

- `since()` compares `publishedAt()` as a moment in time (see [Publication Date as a PHP Date](#publication-date-as-a-php-date) for dates without an offset; the third argument `assumedTimeZone` sets their zone and the zone of a string argument). A string must be ISO 8601, otherwise `InvalidInputException` is thrown. Articles without a usable date are dropped unless `keepUndated: true`, while the Actors' `startDate` filter keeps them.
- `groupByCollection()` returns a `Collection` of `ScrapeResult` objects by collector key. Each has that collector's articles and errors; a collector with only errors gets a group without articles, one with neither gets none.

### Save Results to a File

```php
$json = $result->toJson(JSON_PRETTY_PRINT);

$result->saveTo('news.json');                      // articles and errors
$result->onlyArticles()->saveTo('news.csv');       // articles only
$result->saveTo('news.jsonl', overwrite: true);    // articles only, one per line
$result->saveTo('news.txt', ExportFormat::Jsonl);  // GlobalNewsScraper\ExportFormat
```

| Format | Extension | Content |
| --- | --- | --- |
| JSON | `.json` | `toJson()`: `entries` and `errors`, restorable with `ScrapeResult::fromArray()` |
| JSON Lines | `.jsonl`, `.ndjson` | One article per line in the shape of `Article::toArray()`. **No errors** |
| CSV | `.csv` | A header row and one row per article with the same fields. **No errors** |

- **Existing files:** `saveTo()` never replaces a file unless you pass `overwrite: true`; it throws `ExportException`. An overwritten file keeps its permissions. A symbolic link counts as existing and is replaced, not followed.
- **Atomic:** the content is written to a temporary file in the same directory and then takes the target name, so the target is complete or untouched. The directory must exist and be writable; only local paths are accepted.
- **Encoding:** the output is UTF-8 without a byte order mark. Invalid bytes become U+FFFD.
- **CSV safety:** scraped text is untrusted. A cell that a spreadsheet could run as a formula (it starts with `=`, `+`, `-`, `@`, their full-width forms, also after white space or invisible characters, or with a tab or line break) gets a leading apostrophe `'`. Every field is quoted (RFC 4180, `\r\n` row ends, no backslash escaping: read it with `fgetcsv($handle, null, ',', '"', '')`), NUL bytes are removed, lists and objects such as `authors` and `tags` are JSON. So CSV values can differ from the originals; use JSON or JSON Lines to process the data. Long texts can exceed the cell limit of your spreadsheet.

## Configure Concurrency and Timeouts for the PHP News Scraper

```php
use GlobalNewsScraper\Config;

$client = (new Client(new Config(maxConcurrency: 3, timeout: 1200)))->setApifyKey($apiToken);
```

| Option | Default | Meaning |
| --- | --- | --- |
| `maxConcurrency` | 2 | Collector runs active at the same time. Every run already fetches several articles in parallel on the shared news backend, so the default of 2 keeps the load moderate. Raise it for faster multi-country runs. |
| `timeout` | 900 | Seconds to wait for each collector run to finish. When it expires, the run is aborted (best effort) so it does not keep running and billing; the collector error then says `Actor run did not finish within N seconds and was aborted`. |
| `runTimeout` | null | Apify's own time limit of each collector run in seconds. Null sends `timeout` (default 900), 0 sends nothing and keeps the Actor's own default. See [Run Timeout](#run-timeout). |
| `requestTimeout` | 90 | Timeout in seconds of a single HTTP request. |
| `maxRetries` | 3 | How often a failed request is repeated (0 turns retrying off). See below. |
| `retryBaseDelay` | 1.0 | Seconds before the first retry; doubles with each retry. |
| `retryMaxDelay` | 30.0 | Longest wait before a retry. |
| `baseUrl` | `https://api.apify.com/v2` | Apify API base URL. |

### Run Timeout

Every collector run is started with the Apify `timeout` query parameter (seconds), so the platform stops the run when the client has given up instead of letting it run to the Actor's own limit and bill. By default the value is `Config::$timeout` (900 s). Set `Config::$runTimeout` to use another value, or `0` to send nothing and keep the Actor's own default. `scrape()` takes a last optional argument `runTimeout` that overrides the config for one call (`0` again means the Actor default); negative values throw. The parameter is only sent on the run-start request (`POST /acts/{id}/runs`), never when polling, aborting or reading datasets, and it is separate from `requestTimeout`, the HTTP timeout of a single request.

```php
$client->scrape(Source::BbcNews, runTimeout: 300);
```

### Aborting Runs

When `timeout` expires, every collector run that is still pending is aborted with `POST /actor-runs/{id}/abort` (own request timeout of at most 10 seconds, no retries). A failing abort never hides the timeout: the error then keeps the plain message without `and was aborted`. Runs that ended as FAILED, ABORTED or TIMED-OUT never trigger an abort, and rate limits and connection errors only when they use up `timeout` while polling (see Retries and Backoff). `ApiException::$runId` carries the run ID when known. When a run ends as FAILED, ABORTED or TIMED-OUT and the Actor reported a `statusMessage`, the message reads `Actor run ended with status FAILED: <reason>` (control characters stripped, at most 500 characters) and the raw reason (at most 2000 characters) is available as `$e->statusMessage` on `ApiException` and as `$error->statusMessage` on `CollectorError` (null when the Actor gave none; `toArray()` emits it only when set). The run ID is `$e->runId` or `$error->runId`. To stop a run yourself, call `$client->abort($runId)` with the ID or a `Run` handle (throws `ApiException`, `RateLimitException` or `SpendingLimitException`, and `InvalidInputException` for a malformed ID).

### Retries and Backoff

Failed requests are retried automatically, by default up to 3 times with exponential backoff (1 s, 2 s, 4 s, each with 50 to 100 percent jitter, at most 30 s):

- **Spending limit (HTTP 402, monthly usage or spending limit of the Apify account reached):** never retried. It is thrown as `SpendingLimitException` (extends `ApiException`, code 402, `$errorType` = the Apify error `type` or null, the API's message in the exception message, control characters stripped, capped at 500 characters). In `scrape()` the limit applies to every collector, so no further collector run is started after the first 402 (the skipped collectors get a `CollectorError` starting with `Not started: `). If at least one article was collected, `scrape()` returns the partial result and the affected collectors are `CollectorError` entries whose message says `Apify spending limit reached (HTTP 402)`; only if no article was collected at all, `scrape()` throws the `SpendingLimitException`. `abort()` throws it directly.
- **Rate limits (429):** retried after at least the `Retry-After` time, given in seconds or as an HTTP date. Without the header the backoff delay is used. If the server asks for more than `retryMaxDelay`, the run fails right away with a `RateLimitException`.
- **Server errors (5xx) and connection failures:** retried for polling and reading results. A `Retry-After` on a 503 is honoured the same way (a longer one than `retryMaxDelay` throws an `ApiException` right away).
- **Starting a run:** only retried after a 429, or after a connection failure that shows the request was never sent (DNS, connect or TLS error, and on Guzzle 8 a connect timeout). After a 5xx or any other timeout the run may already exist, and a repeat would be billed twice, so the error is reported instead. Guzzle 7 reports a connect timeout like a read timeout (cURL error 28), so there neither is repeated.
- **Other errors (4xx, including 408):** never retried.

**Overall time limit:** `Config::$timeout` also bounds the retries. It covers the run start, the wait for the run and all retries of each collector run: no retry is started whose wait would pass it, and the timeout of each request is cut to the time left plus 5 seconds. Reading the results of a successful run always gets at least 30 seconds, so a call can take somewhat longer than `timeout`. If polling fails because this time is used up (a skipped retry, or a request that outlived it), the client aborts the run (best effort) and the error of that last request is thrown.

**Long `Retry-After`:** a wait above `retryMaxDelay` is neither waited out nor shortened to it. Retrying earlier than the server asks would most likely be rejected again and use up the retries, so the error is thrown at once: wait as long as you need before calling again, or raise `retryMaxDelay`.

**Logging retries:** pass a callback as the fourth `Client` argument. It gets a `RetryEvent` (`GlobalNewsScraper\DTO\RetryEvent`) before the wait of each retry, with `method`, `path`, `retry` (from 1), `maxRetries`, `delay` (seconds), `status` (HTTP status or null), `curlError` (cURL error number or null) and `message()`. It is safe to log: it holds no token, query or body, and IDs in the path are masked. Anything the callback throws is ignored.

```php
$client = new Client(onRetry: static fn (\GlobalNewsScraper\DTO\RetryEvent $event) => error_log($event->message()));
```

A collector that still fails after the retries is reported in `ScrapeResult::$errors` as before.

### Exception messages

Exception messages (and so `CollectorError::$message`) never contain the request URL, query string, headers or the raw response body, so they are safe to log. They are built from the HTTP status and the Apify error `type` and `message` of the JSON error body, for example `Apify API request failed (HTTP 502): internal-error: Try again later`. Without a usable body the message is `Apify API request failed (HTTP 502)`. Failures without a response read `Apify API request failed: connection error (ConnectException)` or `Apify API request failed: timed out (ConnectException)`. A 429 reads `Apify API rate limit reached (retry after N seconds)` and a 402 `Apify spending limit reached (HTTP 402): <Apify message>`. Every message part is capped at 500 characters.

The raw response body (control characters stripped, at most 2000 characters) is available as `$e->responseBody` on `ApiException`, `RateLimitException` and `SpendingLimitException` (null when there was no body):

```php
try {
    $client->abort($runId);
} catch (ApiException $e) {
    error_log($e->getMessage());
    $details = $e->responseBody; // may contain the full API answer, treat as sensitive
}
```

The underlying Guzzle exception stays available via `getPrevious()`; its message contains the request URL, so do not log it blindly.

## News Sources and Collectors Included

45+ collectors cover 1,000+ outlets. `Source::cases()` lists all of them, `Collector::cases()` the collectors, and `Collector::sources()` the outlets of one collector.

| Collection key | Collector |
| --- | --- |
| `us` | United States |
| `br` | Brazil (Portuguese) |
| `uk` | United Kingdom |
| `mx` | Mexico |
| `latam` | Latin America (regional) |
| `sea` | Southeast Asia (regional) |
| `au` | Australia (English) |
| `ph` | Philippines (English, Tagalog and other Philippine languages) |
| `th` | Thailand |
| `arab` | Arabic-language (regional) |
| `scandinavian` | Denmark, Norway, Sweden (regional) |
| `ng` | Nigeria |
| `kr-ko` | South Korea (Korean) |
| `id` | Indonesia |
| `jp` | Japan (Japanese) |
| `ca` | Canada (English) |
| `vn` | Vietnam (Vietnamese) |
| `in-en` | India (English) |
| `pl` | Poland (Polish) |
| `tr` | Turkey (Turkish) |
| `tw` | Taiwan (Traditional Chinese) |
| `it` | Italy (Italian) |
| `ch` | Switzerland (German, French, Italian) |
| `in` | India (Hindi) |
| `kr-en` | South Korea (English) |
| `fr` | France |
| `il-en` | Israel (English) |
| `de` | Germany (German) |
| `es` | Spain |
| `il` | Israel (Hebrew) |
| `nl` | Netherlands (Dutch) |
| `tw-en` | Taiwan (English) |
| `jp-en` | Japan (English) |
| `id-en` | Indonesia (English) |
| `us-finance` | United States (finance and markets) |
| `us-tech` | United States (tech) |
| `uk-finance` | United Kingdom (finance and the City) |
| `uk-tech` | United Kingdom (tech) |
| `in-finance` | India (English, finance and business) |
| `defense` | Defense and security (topic, English) |
| `energy-climate` | Energy and climate (topic, English) |
| `startups-vc` | Startups and venture capital (topic, English) |
| `supply-chain` | Supply chain, logistics and shipping (topic, English) |
| `biopharma` | Biotech, pharma and healthcare (topic, English) |
| `insurance` | Insurance and reinsurance (topic, English) |
| `marketing` | Marketing, advertising and adtech (topic, English) |
| `sports-business` | Sports business and esports (topic, English) |

Some outlets are offered by several collectors, for example `La Jornada` by `latam` and `mx` or `The Record` by `us-tech` and `defense`; each is run once, on one of these collectors, preferably one that is needed anyway.

## Global News Scraper PHP Examples

See [`examples/`](examples) for runnable scripts (set `APIFY_API_TOKEN` first):

- `01-basic.php`: scrapes three outlets from three countries (New York Times, BBC News, Taipei Times) in one call, merges them into a single article collection, and prints the total plus a per-collector count via each article's `collection` field. Failed collectors are listed from `$result->errors`.
- `02-search-query.php`: passes `searchQuery` to keep only articles whose title, text or URL contain a keyword (here `semiconductor`) across Washington Post, The Guardian and Rappler, and prints `[collection] title` per match.
- `03-date-range.php`: restricts the run to the last seven days with `startDate` and `endDate` (a `DateTimeImmutable` and a `YYYY-MM-DD` string; both types are accepted) and prints the articles newest first, sorted by `datePublish`.
- `04-limit-and-merge.php`: sets `limit: 10`, which applies per collector run (four collectors return up to 40 articles), then filters to text articles with `isArticle()`, sorts newest first, and keeps the five latest titles per collector.
- `05-analysis-addons.php`: switches on every paid analysis add-on (sentiment, tags, summary, 5W1H, language detection, text repair) for five articles per collector and prints each typed enrichment result. Add-ons are billed per enriched article on top of the article price.
- `06-error-handling.php`: shows the three exceptions `scrape()` can throw (`InvalidInputException`, `MissingApiKeyException`, `ApiException`) and the non-throwing case: a failed collector run lands in `$result->errors` while the others still deliver, and `isComplete()` tells you whether the result is partial.
- `07-source-groups.php`: a Spanish and Portuguese language brand monitor: mixes `SourceGroup::Spanish`, `SourceGroup::Portuguese`, a single `Source` and a `Collector`, previews the collector runs with `plan()` before paying for them, scrapes with `searchQuery`, `startDate` and `limit`, counts mentions per `collection` and shows how to filter a group before passing it on.

## Global News Scraper FAQ

### How do I scrape news from several countries in PHP?

Install the package, set your Apify API token and pass the outlets you want to `Client::scrape()`. The library finds the collectors, runs them and returns one merged collection. See the Quick Start above.

### How much does it cost to scrape news articles?

$0.0002 per collector run plus $1.00 to $2.00 per 1,000 articles, plus optional add-ons. The pricing section above has worked examples.

### Which outlets can I use?

1,000+ outlets from 45+ collectors, see the table above. Every outlet is a `Source` enum case with a display name from `Source::label()`.

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

PHP 8.3 or newer, Guzzle 7 or 8 and `illuminate/collections`. No Laravel needed.

## Powered by Apify

The articles come from the news collector Actors of [bite-sized-insights](https://apify.com/bite-sized-insights) on [Apify](https://apify.com), which return titles, authors, publication dates and full text from regional news outlets. [Create a free account](https://console.apify.com/sign-up) to get your API token.

## Contributing and Tests

Run `composer check` for code style, static analysis and tests. See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT
