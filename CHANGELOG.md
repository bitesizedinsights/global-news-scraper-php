# Changelog

All notable changes to this project are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- `limit` above 10000 now throws `InvalidInputException` before any request, mirroring the maximum of the Actor input schemas, instead of failing every collector run. `searchQuery` (1-100 characters), dates and `limit >= 0` were already validated.

### Added

- Automatic retrying with exponential backoff and jitter (`Config::$maxRetries`, `$retryBaseDelay`, `$retryMaxDelay`): rate limits honour `Retry-After` (seconds or HTTP date, a missing header falls back to the backoff), 5xx and connection failures are retried, and starting a run is not repeated after a 5xx or timeout. `Client` takes an optional sleeper as third constructor argument.
- `SourceGroup` enum (`All` and one case per language: English, Spanish, French, Portuguese, Indonesian, Arabic, Danish, Norwegian, Swedish) and `Source::language()`.
- `Client::scrape()` and `Client::plan()` accept a `Source`, `SourceGroup` or `Collector` (alone or mixed in an array or collection); groups and collectors are expanded and deduplicated.
- Example `07-source-groups.php`.
- `th` collector (Thailand, 25 outlets, 15 of them new) and `SourceGroup::Thai`.
- `kr-ko` collector (South Korea, 22 outlets) and `SourceGroup::Korean`.
- `jp` collector (Japan, Japanese-language, 21 outlets) and `SourceGroup::Japanese`.
- `vn` collector (Vietnam, 19 outlets, 16 of them new) and `SourceGroup::Vietnamese`.
- `pl` collector (Poland, 12 outlets) and `SourceGroup::Polish`.
- `tr` collector (Turkey, 11 outlets) and `SourceGroup::Turkish`.
- `it` collector (Italy, 10 outlets) and `SourceGroup::Italian`.
- `de` collector (Germany, 6 outlets) and `SourceGroup::German`.

## [0.1.0] - 2026-11-08

### Added

- `Client::scrape()` maps `Source` cases back to the 13 regional news collector Actors, runs them separately with a configurable concurrency, merges the articles, deduplicates them by URL and adds the collector as `collection` to every article.
- Options: `searchQuery`, `startDate`, `endDate`, `limit` and the analysis add-ons `withSentiment`, `withTags`, `withSummary`, `with5w1h`, `withLanguageDetection` and `withFixText`.
- `Client::plan()` to preview the collector runs without sending a request.
- `ScrapeResult` with an `Illuminate\Support\Collection` of `Article` objects and the per-collector errors of failed runs.
- Generated `Source` enum (302 outlets) and source map, regenerable with `scripts/generate-sources.php`.
- `Client::setApifyKey()` with an environment fallback (`APIFY_API_TOKEN`, `APIFY_TOKEN`) and `MissingApiKeyException`.
