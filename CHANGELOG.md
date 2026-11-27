# Changelog

All notable changes to this project are documented in this file. The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed

- Duplicate articles are now found across URL variants: the dedupe of `Client::scrape()` compares normalised URLs, ignoring `http`/`https`, `www.`, default ports, trailing slashes, in-page fragments, query parameter order and tracking parameters (`utm_*`, `fbclid`, `gclid` and similar); the first article is kept with its URL unchanged.
- Retry safety: starting a run is no longer repeated after a `ConnectException` that can mean the request was already sent. Guzzle 7 reports a read timeout (cURL error 28) and an empty reply (52) that way, so a run could be started and billed twice. It is now repeated only after a 429 or when the request provably never left (cURL errors 5, 6, 7 and 35, a Guzzle 8 `ConnectTimeoutException`, or a connection failure without a cURL code that is not a timeout). On Guzzle 7 a connect timeout is therefore no longer retried on the run start.
- Retries now respect `Config::$timeout`, which covers the run start, the wait and all retries of each collector run: a retry whose wait would pass it is skipped, each request's timeout is cut to the time left plus 5 seconds (reading the results of a successful run keeps at least 30 seconds), and when polling fails because the time is used up the run is aborted (best effort).
- `Retry-After` is now honoured on 503 responses too, not only on 429. One longer than `retryMaxDelay` is not waited out and the error is thrown.

### Changed

- Exception messages no longer embed Guzzle's message, so they contain no request URL, query string or raw response body. They are built from the HTTP status and the Apify error `type` and `message` (`Apify API request failed (HTTP 502): type: message`, generic without a parsable body), transport failures read `Apify API request failed: connection error|timed out (ClassName)`, 429 reads `Apify API rate limit reached (retry after N seconds)` (before: `Rate limit exceeded`). `CollectorError` messages change accordingly (before: `API request failed with status 500: ...`).
- A failed, aborted or timed-out run now reads `Actor run ended with status X: <statusMessage>` when the Actor reported a non-empty `statusMessage` (control characters stripped, capped at 500 characters); without one the message is unchanged.
- Behavior change: starting a collector run now sends the Apify `timeout` query parameter, by default `Config::$timeout`, so the platform stops a run when the client has given up (before, the run kept the Actor's own limit, 900 s). Set `Config::$runTimeout = 0` to send nothing and keep the Actor default.

### Added

- Async API: `Client::start()` starts the collector runs of `scrape()` and returns a `RunBatch` of `Run` handles (Apify run IDs) without waiting; `Client::getRun()` looks one run up, `Client::wait()` waits for several runs (never aborts) and `Client::results()` returns their articles as a `ScrapeResult`, merged and deduplicated like the one of `scrape()`. A failed start keeps the handles of the other runs and a start that may have reached Apify is never repeated. Bare run IDs are matched to their collector through the run's Actor. New: `DTO\Run`, `DTO\RunBatch` and the `RunStatus` enum.
- `Client::abort()` also accepts a `Run` handle. Behavior change: a run ID with characters no Apify ID has now throws `InvalidInputException` without a request.
- `defense` collector (defense and security, English), `energy-climate` collector (energy and climate, English) and `startups-vc` collector (startups and venture capital, English).
- `supply-chain` collector (supply chain, logistics and shipping, English), `biopharma` collector (biotech, pharma and healthcare, English) and `insurance` collector (insurance and reinsurance, English).
- `us-finance`, `us-tech`, `uk-finance`, `uk-tech` and `in-finance` collectors (finance and tech, English).
- `ApiException::$responseBody` (`?string`, last optional constructor parameter, also on `RateLimitException` and `SpendingLimitException`): the raw response body, control characters stripped and capped at 2000 characters, null without a body.
- `SpendingLimitException` (extends `ApiException`) for Apify HTTP 402 (usage or spending limit reached): code 402, `$errorType` with the Apify error type, never retried. `scrape()` stops starting collector runs after the first 402 and returns partial results with `CollectorError` entries when any article was collected; it throws the exception only when none was.
- `Config::$runTimeout` (`?int` seconds, default null = `Config::$timeout`, 0 = Actor default, negative throws `InvalidArgumentException`) and a last optional `runTimeout` argument of `Client::scrape()` that overrides it per call (negative throws `InvalidInputException`). Only the run-start request (`POST /acts/{id}/runs`) carries it.
- When `Config::$timeout` expires while waiting for a run, the run is now aborted (best effort, `POST /actor-runs/{id}/abort`, own timeout of at most 10 seconds, errors swallowed) so it does not keep running and billing. The collector error then reads `... and was aborted`; if the abort fails, the original message is kept. Only our own deadline aborts, never failed runs, rate limits or connection errors.
- `Client::abort(string $runId)` and `ApiException::$runId`.
- `ApiException::$statusMessage` and `CollectorError::$statusMessage` (`?string`, default null), the raw `statusMessage` of a failed run capped at 2000 characters. `CollectorError::toArray()` emits `statusMessage` only when set, so the serialized shape is unchanged for runs without one.
- `CollectorError::$runId`, the Apify run ID of a failed or timed-out collector run when known (otherwise null), so callers can inspect or abort the run. `toArray()` emits `runId` only when set.

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
