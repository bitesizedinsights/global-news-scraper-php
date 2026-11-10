<?php

declare(strict_types=1);

namespace GlobalNewsScraper;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use GlobalNewsScraper\DTO\Article;
use GlobalNewsScraper\DTO\CollectorError;
use GlobalNewsScraper\DTO\RunPlan;
use GlobalNewsScraper\DTO\ScrapeResult;
use GlobalNewsScraper\Exception\ApiException;
use GlobalNewsScraper\Exception\InvalidInputException;
use GlobalNewsScraper\Exception\MissingApiKeyException;
use GlobalNewsScraper\Exception\RateLimitException;
use GlobalNewsScraper\Support\Planner;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Promise\Coroutine;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Each;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

final class Client
{
    private const FAILED_STATUSES = ['FAILED', 'ABORTED', 'TIMED-OUT'];

    private const RUNNING_STATUSES = ['READY', 'RUNNING'];

    /** Longest wait Apify allows per request. */
    private const MAX_WAIT_SECONDS = 60;

    private readonly Config $config;

    private readonly ClientInterface $http;

    private ?string $apiKey = null;

    /** @var Closure(float): void */
    private readonly Closure $sleeper;

    /**
     * @param  (Closure(float): void)|null  $sleeper  Waits the given seconds between retries; replaced in tests.
     */
    public function __construct(?Config $config = null, ?ClientInterface $http = null, ?Closure $sleeper = null)
    {
        $this->config = $config ?? new Config;
        $this->http = $http ?? new HttpClient;
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    /**
     * Set the Apify API key. Without it, `APIFY_API_TOKEN` or `APIFY_TOKEN` from the environment is used.
     */
    public function setApifyKey(string $apiKey): static
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    /**
     * Scrape the given news sources. Each source is mapped back to its collector Actor, the collectors run
     * separately (up to `Config::$maxConcurrency` at a time) and their articles are merged and deduplicated by URL.
     * Every article carries the key of its collector in `collection`.
     *
     * A failing collector run does not throw: it is reported in `ScrapeResult::$errors` and the other collectors'
     * articles are still returned.
     *
     * What to scrape (`$sources`) can be a `Source`, a `SourceGroup` (`SourceGroup::All` or one language), a
     * `Collector` (all sources it offers) or an array/collection mixing them. Groups and collectors are expanded and
     * duplicates removed. Big selections start many billed collector runs: check `plan()` and set `limit` first.
     *
     * @param  array<mixed>|Collection<array-key, mixed>|Source|SourceGroup|Collector  $sources  Sources to scrape.
     * @param  string|null  $searchQuery  Keep only articles whose title, text or URL contain this keyword
     *                                    (1 to 100 characters). Filtering may process more articles than it returns.
     * @param  DateTimeInterface|string|null  $startDate  Only articles published on or after this day
     *                                                    (`YYYY-MM-DD` or a date object, not in the future).
     * @param  DateTimeInterface|string|null  $endDate  Only articles published on or before this day
     *                                                  (`YYYY-MM-DD` or a date object, not older than 5 years).
     * @param  int|null  $limit  Maximum articles per collector run, 0 to 10000 (0 = unlimited). Null keeps the Actor
     *                           default of 100. The limit applies to each run, not to the merged result, and the
     *                           Actors may process more articles than they return when other filters are set.
     * @param  bool  $withSentiment  Add a sentiment score to each article (`Article::$sentiment`).
     * @param  bool  $withTags  Add category tags to each article (`Article::$tags`).
     * @param  bool  $withSummary  Add a compact summary to each article (`Article::$summary`).
     * @param  bool  $with5w1h  Add a who, what, when, where, why and how extraction (`Article::$fiveW1h`).
     * @param  bool  $withLanguageDetection  Detect the language from the text (`Article::$detectedLanguage`);
     *                                       `Article::$language` keeps the value declared by the source.
     * @param  bool  $withFixText  Repair broken text such as mojibake (`Article::$fixedText`); `Article::$text`
     *                             stays unchanged.
     *
     * @throws InvalidInputException
     * @throws MissingApiKeyException
     */
    public function scrape(
        array|Collection|Source|SourceGroup|Collector $sources,
        ?string $searchQuery = null,
        DateTimeInterface|string|null $startDate = null,
        DateTimeInterface|string|null $endDate = null,
        ?int $limit = null,
        bool $withSentiment = false,
        bool $withTags = false,
        bool $withSummary = false,
        bool $with5w1h = false,
        bool $withLanguageDetection = false,
        bool $withFixText = false,
    ): ScrapeResult {
        $plans = $this->plan($sources);

        $options = [];

        if ($searchQuery !== null) {
            $options['search_query'] = $this->searchQuery($searchQuery);
        }

        $start = $startDate === null ? null : $this->date($startDate, 'startDate');
        $end = $endDate === null ? null : $this->date($endDate, 'endDate');
        $today = gmdate('Y-m-d');

        if ($start !== null) {
            if ($start > $today) {
                throw new InvalidInputException('startDate must not be in the future');
            }

            $options['start_date'] = $start;
        }

        if ($end !== null) {
            if ($end < (new DateTimeImmutable('-5 years', new DateTimeZone('UTC')))->format('Y-m-d')) {
                throw new InvalidInputException('endDate must not be older than 5 years');
            }

            $options['end_date'] = $end;
        }

        if ($start !== null && $end !== null && $end < $start) {
            throw new InvalidInputException('endDate must not be before startDate');
        }

        if ($limit !== null) {
            if ($limit < 0) {
                throw new InvalidInputException('limit must not be negative');
            }

            if ($limit > 10000) {
                throw new InvalidInputException('limit must not be greater than 10000');
            }

            $options['limit'] = $limit;
        }

        foreach ([
            'with_sentiment' => $withSentiment,
            'with_tags' => $withTags,
            'with_summary' => $withSummary,
            'with_5w1h' => $with5w1h,
            'with_language_detection' => $withLanguageDetection,
            'with_fix_text' => $withFixText,
        ] as $flag => $enabled) {
            if ($enabled) {
                $options[$flag] = true;
            }
        }

        $this->resolveApiKey();

        return $this->execute($plans, $options);
    }

    private function searchQuery(string $query): string
    {
        $query = trim($query);

        if ($query === '' || mb_strlen($query) > 100) {
            throw new InvalidInputException('searchQuery must be 1 to 100 characters');
        }

        return $query;
    }

    private function date(DateTimeInterface|string $date, string $name): string
    {
        if ($date instanceof DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidInputException("{$name} must be a date in YYYY-MM-DD format");
        }

        return $date;
    }

    /**
     * Show which collector runs `scrape()` would start for these sources. Sends no request.
     *
     * @param  array<mixed>|Collection<array-key, mixed>|Source|SourceGroup|Collector  $sources  As for `scrape()`.
     * @return list<RunPlan>
     *
     * @throws InvalidInputException
     */
    public function plan(array|Collection|Source|SourceGroup|Collector $sources): array
    {
        $list = match (true) {
            $sources instanceof Collection => $sources->all(),
            is_array($sources) => $sources,
            default => [$sources],
        };

        if ($list === []) {
            throw new InvalidInputException('At least one source is required');
        }

        $unique = [];

        foreach ($list as $item) {
            $expanded = match (true) {
                $item instanceof Source => [$item],
                $item instanceof SourceGroup, $item instanceof Collector => $item->sources(),
                default => throw new InvalidInputException(
                    'Sources must be Source, SourceGroup or Collector cases',
                ),
            };

            foreach ($expanded as $source) {
                $unique[$source->value] = $source;
            }
        }

        return Planner::plan(array_values($unique));
    }

    /**
     * @param  list<RunPlan>  $plans
     * @param  array<string, mixed>  $options
     */
    private function execute(array $plans, array $options): ScrapeResult
    {
        $outcomes = [];

        $runs = (function () use ($plans, $options) {
            foreach ($plans as $index => $plan) {
                yield $index => $this->runCollector($plan, $this->buildInput($plan, $options));
            }
        })();

        Each::ofLimit(
            $runs,
            max(1, $this->config->maxConcurrency),
            static function (mixed $outcome, mixed $index) use (&$outcomes): void {
                $outcomes[$index] = $outcome;
            },
        )->wait();

        ksort($outcomes);

        $articles = [];
        $errors = [];

        foreach ($outcomes as $outcome) {
            if ($outcome instanceof CollectorError) {
                $errors[] = $outcome;

                continue;
            }

            array_push($articles, ...$outcome);
        }

        return new ScrapeResult(
            (new Collection($articles))->unique('url')->values(),
            new Collection($errors),
        );
    }

    /**
     * Only the sources assigned to this collector are switched on; all its other source toggles are off.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildInput(RunPlan $plan, array $options): array
    {
        $assigned = array_map(static fn (Source $s): string => $s->value, $plan->sources);
        $input = [];

        foreach (SourceMap::BY_COLLECTOR[$plan->collector->value] as $key) {
            $input[$key] = in_array($key, $assigned, true);
        }

        return $options + $input;
    }

    /**
     * Start the run, wait for it and read its dataset. API failures resolve to a CollectorError.
     *
     * @param  array<string, mixed>  $input
     * @return PromiseInterface Resolves to `list<Article>` or a `CollectorError`.
     */
    private function runCollector(RunPlan $plan, array $input): PromiseInterface
    {
        return Coroutine::of(function () use ($plan, $input) {
            try {
                $actor = str_replace('/', '~', $plan->collector->actorId());
                $run = $this->runData(yield $this->request('POST', "/acts/{$actor}/runs", ['json' => $input]));
                $deadline = time() + $this->config->timeout;

                while (true) {
                    $status = $run['status'] ?? null;

                    if ($status === 'SUCCEEDED') {
                        break;
                    }

                    if (is_string($status) && in_array($status, self::FAILED_STATUSES, true)) {
                        throw new ApiException("Actor run ended with status {$status}");
                    }

                    if (! is_string($status) || ! in_array($status, self::RUNNING_STATUSES, true)) {
                        throw new ApiException('Invalid API response: missing or unknown run status');
                    }

                    $remaining = $deadline - time();

                    if ($remaining <= 0) {
                        throw new ApiException("Actor run did not finish within {$this->config->timeout} seconds");
                    }

                    $runId = $run['id'] ?? null;

                    if (! is_string($runId)) {
                        throw new ApiException('Invalid API response: missing run ID');
                    }

                    $run = $this->runData(yield $this->request('GET', "/actor-runs/{$runId}", [
                        'query' => ['waitForFinish' => min(self::MAX_WAIT_SECONDS, $remaining)],
                    ]));
                }

                $datasetId = $run['defaultDatasetId'] ?? null;

                if (! is_string($datasetId) || $datasetId === '') {
                    throw new ApiException('Invalid API response: missing dataset ID');
                }

                $items = yield $this->request('GET', "/datasets/{$datasetId}/items", ['query' => ['format' => 'json']]);

                // A coroutine resolves with its final yielded value, not with a return value
                yield Create::promiseFor($this->articles($plan->collector, $items));
            } catch (ApiException $e) {
                yield Create::promiseFor(new CollectorError($plan->collector, $e->getMessage()));
            }
        });
    }

    /**
     * @param  array<mixed>  $items
     * @return list<Article>
     */
    private function articles(Collector $collector, array $items): array
    {
        $articles = [];

        foreach (array_values($items) as $index => $item) {
            try {
                if (! is_array($item)) {
                    throw new InvalidArgumentException('Item must be an object');
                }

                $articles[] = Article::fromArray($item, $collector->value);
            } catch (InvalidArgumentException $e) {
                throw new ApiException("Invalid dataset item at index {$index}: ".$e->getMessage(), 0, $e);
            }
        }

        return $articles;
    }

    /**
     * @param  array<mixed>  $result
     * @return array<mixed>
     */
    private function runData(array $result): array
    {
        $data = $result['data'] ?? null;

        if (! is_array($data)) {
            throw new ApiException('Invalid API response: missing run data');
        }

        return $data;
    }

    /**
     * @throws MissingApiKeyException
     */
    private function resolveApiKey(): string
    {
        $key = $this->apiKey ?: (getenv('APIFY_API_TOKEN') ?: getenv('APIFY_TOKEN'));

        if (! is_string($key) || $key === '') {
            throw new MissingApiKeyException;
        }

        return $key;
    }

    /**
     * Sends a request and retries what is safe to repeat: rate limits (429, the request was not processed), server
     * errors (5xx) and connection failures. Starting a run (POST) is only repeated after a 429 or a failed
     * connection, because after a 5xx or a timeout the run may already exist and a repeat would bill it twice.
     *
     * @param  array<string, mixed>  $options
     * @return PromiseInterface Resolves to the decoded JSON body.
     */
    private function request(string $method, string $path, array $options = []): PromiseInterface
    {
        $options['headers'] = [
            'Authorization' => 'Bearer '.$this->resolveApiKey(),
            'Accept' => 'application/json',
        ];
        $options['timeout'] = $this->config->requestTimeout;
        $url = rtrim($this->config->baseUrl, '/').$path;

        return Coroutine::of(function () use ($method, $url, $options) {
            for ($attempt = 0; ; $attempt++) {
                try {
                    $response = yield $this->http->requestAsync($method, $url, $options);

                    $decoded = $this->decode($response);
                } catch (BadResponseException $e) {
                    $status = $e->getResponse()->getStatusCode();
                    $retryAfter = $status === 429 ? $this->retryAfter($e->getResponse()) : null;
                    $retryable = $status === 429 || ($status >= 500 && $method !== 'POST');
                    $delay = $retryable && $attempt < $this->config->maxRetries
                        ? $this->retryDelay($attempt, $retryAfter)
                        : null;

                    if ($delay === null) {
                        throw $this->failure($e, $this->retryDelay($attempt, null, false));
                    }

                    ($this->sleeper)($delay);

                    continue;
                } catch (ApiException $e) {
                    throw $e;
                } catch (GuzzleException $e) {
                    // A failed connection never reached the server, so it is safe to repeat even for POST.
                    $retryable = $e instanceof ConnectException || $method !== 'POST';

                    if ($retryable && $attempt < $this->config->maxRetries) {
                        ($this->sleeper)($this->retryDelay($attempt, null) ?? 0.0);

                        continue;
                    }

                    throw $this->failure($e);
                }

                yield Create::promiseFor($decoded);

                return;
            }
        });
    }

    /**
     * Seconds to wait before the next retry: exponential from the base delay with 50 to 100 percent jitter, at least
     * the server's `Retry-After`. Null when the server asks for a longer wait than the configured maximum.
     */
    private function retryDelay(int $attempt, ?int $retryAfter, bool $jitter = true): ?float
    {
        if ($retryAfter !== null && $retryAfter > $this->config->retryMaxDelay) {
            return null;
        }

        $backoff = min($this->config->retryMaxDelay, $this->config->retryBaseDelay * (2 ** $attempt));
        $jittered = $jitter ? $backoff * (random_int(500, 1000) / 1000) : $backoff;

        return max($jittered, (float) ($retryAfter ?? 0));
    }

    /**
     * `Retry-After` in seconds, from either the numeric or the HTTP-date form. Null when missing or unusable.
     */
    private function retryAfter(ResponseInterface $response): ?int
    {
        $value = trim($response->getHeaderLine('Retry-After'));

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : max(0, $timestamp - time());
    }

    /**
     * @return array<mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        try {
            $decoded = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ApiException('Invalid API response: malformed JSON', 0, $e);
        }

        if (! is_array($decoded)) {
            throw new ApiException('Invalid API response: unexpected JSON');
        }

        return $decoded;
    }

    private function failure(mixed $reason, ?float $fallbackRetryAfter = null): Throwable
    {
        if ($reason instanceof BadResponseException) {
            $response = $reason->getResponse();

            if ($response->getStatusCode() === 429) {
                $retryAfter = $this->retryAfter($response) ?? (int) ceil($fallbackRetryAfter ?? 1.0);

                return new RateLimitException('Rate limit exceeded', $retryAfter);
            }

            return new ApiException(
                "API request failed with status {$response->getStatusCode()}: ".$reason->getMessage(),
                $response->getStatusCode(),
                $reason,
            );
        }

        if ($reason instanceof GuzzleException) {
            return new ApiException('API request failed: '.$reason->getMessage(), 0, $reason);
        }

        return $reason instanceof Throwable ? $reason : new ApiException('API request failed');
    }
}
