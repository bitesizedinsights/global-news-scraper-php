# Changelog

All notable changes to this project are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- When `Config::$timeout` expires while waiting for a run, the run is now aborted (best effort, `POST /actor-runs/{id}/abort`, own timeout of at most 10 seconds, errors swallowed) so it does not keep running and billing. The collector error then reads `... and was aborted`; if the abort fails, the original message is kept. Only our own deadline aborts, never failed runs, rate limits or connection errors.
- `Client::abort(string $runId)` and `ApiException::$runId`.

## [0.2.0] - 2026-11-15

### Changed

- Guzzle 8 supported, Guzzle 7 still allowed (`guzzlehttp/guzzle` `^7.0 || ^8.0`).
- `limit` above 10000 now throws `InvalidInputException` before any request, mirroring the maximum of the Actor input schemas, instead of failing every collector run. `searchQuery` (1-100 characters), dates and `limit >= 0` were already validated.

### Added

- `toArray()` and `JsonSerializable` on `Article`, `ScrapeResult`, `CollectorError` and `RunPlan`, in the Actor's snake_case field names (plus `collection` on `Article`), and `fromArray()` on all four so results can be stored and restored. `Article::fromArray()` now takes the collection as an optional argument and falls back to the `collection` entry.
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
- `au` collector (Australia, English, 31 outlets, 29 of them new).
- `br` collector (Brazil, Portuguese, 41 outlets, 39 of them new).
- `ca` collector (Canada, English, 21 outlets).
- `ch` collector (Switzerland, 10 German-, French- and Italian-language outlets, in `SourceGroup::German`, `French` and `Italian`).
- `gt` collector (Guatemala, Spanish, 6 outlets, 5 of them new).
- `id-en` collector (Indonesia, English, 3 outlets, all also offered by `sea`).
- `il` collector (Israel, Hebrew, 6 outlets) and `SourceGroup::Hebrew`.
- `il-en` collector (Israel, English, 7 outlets).
- `in` collector (India, Hindi, 9 outlets) and `SourceGroup::Hindi`.
- `in-en` collector (India, English, 15 outlets).
- `kr-en` collector (South Korea, English, 8 outlets).
- `kurdish` collector (Kurdish media, 9 outlets) and `SourceGroup::Kurmanji`, `Sorani` and `Zazaki`.
- `nl` collector (Netherlands, Dutch, 6 outlets) and `SourceGroup::Dutch`.
- `ph` collector (Philippines, 26 outlets, 12 of them new) and `SourceGroup::Tagalog`, `Cebuano`, `Ilocano`, `Hiligaynon`, `Bikol` and `Waray`.
- `pk` collector (Pakistan, 12 outlets) and `SourceGroup::Urdu`, `Pashto`, `Sindhi` and `Saraiki`.
- `pk-en` collector (Pakistan, English, 11 outlets, 10 of them new).
- `py` collector (Paraguay, Spanish, 4 outlets, 3 of them new).
- `tw` collector (Taiwan, Traditional Chinese, 11 outlets) and `SourceGroup::Chinese`.

## [0.1.0] - 2026-11-08

### Added

- `Client::scrape()` maps `Source` cases back to the 13 regional news collector Actors, runs them separately with a configurable concurrency, merges the articles, deduplicates them by URL and adds the collector as `collection` to every article.
- Options: `searchQuery`, `startDate`, `endDate`, `limit` and the analysis add-ons `withSentiment`, `withTags`, `withSummary`, `with5w1h`, `withLanguageDetection` and `withFixText`.
- `Client::plan()` to preview the collector runs without sending a request.
- `ScrapeResult` with an `Illuminate\Support\Collection` of `Article` objects and the per-collector errors of failed runs.
- Generated `Source` enum (302 outlets) and source map, regenerable with `scripts/generate-sources.php`.
- `Client::setApifyKey()` with an environment fallback (`APIFY_API_TOKEN`, `APIFY_TOKEN`) and `MissingApiKeyException`.
