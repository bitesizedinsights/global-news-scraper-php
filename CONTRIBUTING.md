# Contributing

## Setup

```bash
composer install
composer check   # Pint, PHPStan level 8 and Pest
```

`composer cs:fix` fixes code style. Keep code lines at 120 characters or less.

## Regenerating the sources

`src/Source.php` and `src/SourceMap.php` are generated from the input schemas of the collector Actors. After a collector gains or loses a source, or a new collector is added to `Collector`, run:

```bash
php scripts/generate-sources.php /path/to/apify-news-scraper
composer cs:fix
```

## Live test

`tests/LiveTest.php` runs the real Actors (two collectors, one article each, a fraction of a cent). It is skipped unless `APIFY_LIVE_TEST=1` and a token in `APIFY_API_TOKEN` or `APIFY_TOKEN` are set:

```bash
APIFY_LIVE_TEST=1 APIFY_API_TOKEN=... composer test -- tests/LiveTest.php
```

## Continuous integration

The GitHub Actions workflow runs the tests on PHP 8.3 and 8.4, Pint and PHPStan on every push and pull request. The live test runs only when the workflow is started manually with `live` enabled, and needs the `APIFY_API_TOKEN` repository secret.
