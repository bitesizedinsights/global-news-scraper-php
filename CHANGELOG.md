# Changelog

All notable changes to this project are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-11-08

### Added

- `Client::scrape()` maps `Source` cases back to the 13 regional news collector Actors, runs them separately with a configurable concurrency, merges the articles, deduplicates them by URL and adds the collector as `collection` to every article.
- Options: `searchQuery`, `startDate`, `endDate`, `limit` and the analysis add-ons `withSentiment`, `withTags`, `withSummary`, `with5w1h`, `withLanguageDetection` and `withFixText`.
- `Client::plan()` to preview the collector runs without sending a request.
- `ScrapeResult` with an `Illuminate\Support\Collection` of `Article` objects and the per-collector errors of failed runs.
- Generated `Source` enum (302 outlets) and source map, regenerable with `scripts/generate-sources.php`.
- `Client::setApifyKey()` with an environment fallback (`APIFY_API_TOKEN`, `APIFY_TOKEN`) and `MissingApiKeyException`.
