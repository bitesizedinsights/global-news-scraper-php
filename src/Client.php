<?php

declare(strict_types=1);

namespace GlobalNewsScraper;

use ArrayObject;
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
use GlobalNewsScraper\Exception\SpendingLimitException;
use GlobalNewsScraper\Support\ErrorText;
use GlobalNewsScraper\Support\Planner;
use GlobalNewsScraper\Support\SpendingLimitError;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
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

    /** Seconds a request may run past the deadline, so a long poll of the remaining time can still answer. */
    private const DEADLINE_GRACE_SECONDS = 5;

    /** Minimum seconds for reading the results of a successful run, even when the deadline is nearly used up. */
    private const RESULTS_WINDOW_SECONDS = 30;

    /** cURL errors raised before the request is sent: resolve proxy (5) or host (6), connect (7), TLS (35). */
    private const UNSENT_CURL_ERRORS = [5, 6, 7, 35];

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
     * @param  int|null  $runTimeout  Apify time limit of each collector run in seconds for this call, overriding
     *                                `Config::$runTimeout`. Null = the config value (default: `Config::$timeout`),
     *                                0 = send nothing and keep the Actor's own default. Negative values throw.
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
        ?int $runTimeout = null,
    ): ScrapeResult {
        $plans = $this->plan($sources);

        if ($runTimeout !== null && $runTimeout < 0) {
            throw new InvalidInputException(
                'runTimeout must be a positive number of seconds, or 0 for the Actor default',
            );
        }

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

        return $this->execute($plans, $options, $runTimeout);
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
    private function execute(array $plans, array $options, ?int $runTimeout): ScrapeResult
    {
        $outcomes = [];
        /** @var ArrayObject<string, SpendingLimitException> $limit */
        $limit = new ArrayObject;
        $skipped = [];

        $runs = (function () use ($plans, $options, $runTimeout, $limit, &$skipped) {
            foreach ($plans as $index => $plan) {
                if (isset($limit['e'])) {
                    // The account limit applies to every collector: do not start further runs
                    $skipped[$index] = new CollectorError(
                        $plan->collector,
                        'Not started: '.$limit['e']->getMessage(),
                    );

                    continue;
                }

                yield $index => $this->runCollector($plan, $this->buildInput($plan, $options), $runTimeout);
            }
        })();

        Each::ofLimit(
            $runs,
            max(1, $this->config->maxConcurrency),
            static function (mixed $outcome, mixed $index) use (&$outcomes, $limit): void {
                if ($outcome instanceof SpendingLimitError) {
                    $limit['e'] ??= $outcome->exception;
                    $outcome = $outcome->error;
                }

                $outcomes[$index] = $outcome;
            },
        )->wait();

        $outcomes += $skipped;
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

        // Partial results are returned; only when the limit stopped every collector without a single article it throws
        if (isset($limit['e']) && $articles === []) {
            throw $limit['e'];
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
     * @param  int|null  $runTimeout  Per-call override of the run's Apify time limit (null = config, 0 = none).
     * @return PromiseInterface Resolves to `list<Article>` or a `CollectorError`.
     */
    private function runCollector(RunPlan $plan, array $input, ?int $runTimeout): PromiseInterface
    {
        return Coroutine::of(function () use ($plan, $input, $runTimeout) {
            $knownRunId = null;

            try {
                // One deadline for the whole collector run: start, polling and every retry
                $deadline = microtime(true) + $this->config->timeout;
                $actor = str_replace('/', '~', $plan->collector->actorId());
                $start = ['json' => $input];
                $limit = $runTimeout ?? $this->config->runTimeout ?? $this->config->timeout;

                // Apify's own time limit of the run; only the run start carries it. 0 keeps the Actor default.
                if ($limit > 0) {
                    $start['query'] = ['timeout' => $limit];
                }

                $start['deadline'] = $deadline;
                $run = $this->runData(yield $this->request('POST', "/acts/{$actor}/runs", $start));
                $knownRunId = is_string($run['id'] ?? null) ? $run['id'] : null;

                while (true) {
                    $status = $run['status'] ?? null;

                    if ($status === 'SUCCEEDED') {
                        break;
                    }

                    if (is_string($status) && in_array($status, self::FAILED_STATUSES, true)) {
                        throw $this->failedRun($status, $run['statusMessage'] ?? null, $knownRunId);
                    }

                    if (! is_string($status) || ! in_array($status, self::RUNNING_STATUSES, true)) {
                        throw new ApiException('Invalid API response: missing or unknown run status');
                    }

                    $runId = $run['id'] ?? null;

                    if (! is_string($runId)) {
                        throw new ApiException('Invalid API response: missing run ID');
                    }

                    $remaining = (int) ceil($deadline - microtime(true));

                    if ($remaining <= 0) {
                        // Our own deadline expired: stop the run so it does not keep running and billing
                        $aborted = yield $this->tryAbort($runId);
                        $message = "Actor run did not finish within {$this->config->timeout} seconds";

                        throw new ApiException(
                            $aborted ? "{$message} and was aborted" : $message,
                            0,
                            null,
                            $runId,
                        );
                    }

                    $retrySkipped = false;

                    try {
                        $result = yield $this->request('GET', "/actor-runs/{$runId}", [
                            'query' => ['waitForFinish' => min(self::MAX_WAIT_SECONDS, $remaining)],
                            'deadline' => $deadline,
                            'on_retry_skipped' => static function () use (&$retrySkipped): void {
                                $retrySkipped = true;
                            },
                        ]);
                    } catch (ApiException $e) {
                        // The deadline ends the wait here, so the run must not keep running and billing
                        if ($retrySkipped || microtime(true) >= $deadline) {
                            yield $this->tryAbort($runId);
                        }

                        throw $e;
                    }

                    $run = $this->runData($result);
                }

                $datasetId = $run['defaultDatasetId'] ?? null;

                if (! is_string($datasetId) || $datasetId === '') {
                    throw new ApiException('Invalid API response: missing dataset ID');
                }

                $items = yield $this->request('GET', "/datasets/{$datasetId}/items", [
                    'query' => ['format' => 'json'],
                    'deadline' => max($deadline, microtime(true) + self::RESULTS_WINDOW_SECONDS),
                ]);

                // A coroutine resolves with its final yielded value, not with a return value
                yield Create::promiseFor($this->articles($plan->collector, $items));
            } catch (ApiException $e) {
                $error = new CollectorError(
                    $plan->collector,
                    $e->getMessage(),
                    $e->runId ?? $knownRunId,
                    $e->statusMessage,
                );

                yield Create::promiseFor($e instanceof SpendingLimitException ? new SpendingLimitError($error, $e) : $error);
            }
        });
    }

    /**
     * Aborts an Apify run (`POST /actor-runs/{id}/abort`), e.g. one that is still running after a timeout.
     *
     * @throws ApiException
     * @throws RateLimitException
     * @throws SpendingLimitException
     * @throws MissingApiKeyException
     */
    public function abort(string $runId): void
    {
        $this->request('POST', '/actor-runs/'.rawurlencode($runId).'/abort')->wait();
    }

    /** Exception of a failed, aborted or timed-out run; the message adds the Actor's own reason when it has one. */
    private function failedRun(string $status, mixed $statusMessage, ?string $runId): ApiException
    {
        $message = "Actor run ended with status {$status}";

        if (! is_string($statusMessage) || $statusMessage === '') {
            return new ApiException($message, 0, null, $runId);
        }

        $raw = mb_substr($statusMessage, 0, 2000);
        $clean = ErrorText::clean($raw);

        if ($clean !== '') {
            $message .= ': '.$clean;
        }

        return new ApiException($message, 0, null, $runId, $raw);
    }

    /**
     * Best-effort abort with a short request timeout and no retries.
     *
     * @return PromiseInterface Resolves to true when Apify accepted the abort, false otherwise; never rejects.
     */
    private function tryAbort(string $runId): PromiseInterface
    {
        return Coroutine::of(function () use ($runId) {
            try {
                yield $this->request(
                    'POST',
                    '/actor-runs/'.rawurlencode($runId).'/abort',
                    ['timeout' => min(10, $this->config->requestTimeout), 'retry' => false],
                );
                $aborted = true;
            } catch (Throwable) {
                $aborted = false;
            }

            yield Create::promiseFor($aborted);
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
     * With a deadline, no retry is started whose wait would pass it and each request's timeout is cut to the time left.
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
        $baseTimeout = $options['timeout'] ?? $this->config->requestTimeout;
        $maxRetries = ($options['retry'] ?? true) === false ? 0 : $this->config->maxRetries;
        $deadline = $options['deadline'] ?? null;
        $onRetrySkipped = $options['on_retry_skipped'] ?? null;
        unset($options['retry'], $options['deadline'], $options['on_retry_skipped']);
        $url = rtrim($this->config->baseUrl, '/').$path;

        $request = function () use ($method, $url, $options, $maxRetries, $baseTimeout, $deadline, $onRetrySkipped) {
            for ($attempt = 0; ; $attempt++) {
                $options['timeout'] = $this->cappedTimeout($baseTimeout, $deadline);

                try {
                    $response = yield $this->http->requestAsync($method, $url, $options);

                    $decoded = $this->decode($response);
                } catch (BadResponseException $e) {
                    $status = $e->getResponse()->getStatusCode();
                    $retryAfter = in_array($status, [429, 503], true) ? $this->retryAfter($e->getResponse()) : null;
                    $retryable = $status === 429 || ($status >= 500 && $method !== 'POST');
                    $delay = $retryable && $attempt < $maxRetries
                        ? $this->retryDelay($attempt, $retryAfter)
                        : null;

                    if ($delay !== null && ! $this->endsBeforeDeadline($delay, $deadline)) {
                        if ($onRetrySkipped !== null) {
                            $onRetrySkipped();
                        }

                        $delay = null;
                    }

                    if ($delay === null) {
                        throw $this->failure($e, $this->retryDelay($attempt, null, false));
                    }

                    ($this->sleeper)($delay);

                    continue;
                } catch (ApiException $e) {
                    throw $e;
                } catch (GuzzleException $e) {
                    // A run start is only repeated when the request provably never reached the server.
                    $retryable = $method !== 'POST' || $this->neverSent($e);

                    if ($retryable && $attempt < $maxRetries) {
                        $delay = $this->retryDelay($attempt, null) ?? 0.0;

                        if ($this->endsBeforeDeadline($delay, $deadline)) {
                            ($this->sleeper)($delay);

                            continue;
                        }

                        if ($onRetrySkipped !== null) {
                            $onRetrySkipped();
                        }
                    }

                    throw $this->failure($e);
                }

                yield Create::promiseFor($decoded);

                return;
            }
        };

        return Coroutine::of($request);
    }

    /**
     * True only when the failure proves that the request never reached the server, so repeating it cannot start a
     * second run. A `ConnectException` alone is not enough: Guzzle 7 also throws it when a sent request times out
     * (cURL error 28) or gets no reply (52).
     */
    private function neverSent(GuzzleException $e): bool
    {
        if (! $e instanceof ConnectException) {
            return false;
        }

        if ($e instanceof ConnectTimeoutException) {
            return true;
        }

        if (preg_match('/^cURL error (\d+):/', $e->getMessage(), $match) === 1) {
            return in_array((int) $match[1], self::UNSENT_CURL_ERRORS, true);
        }

        return preg_match('/timed?[ -]?out/i', $e->getMessage()) !== 1;
    }

    /** The per-request timeout, cut to the time left until the deadline (plus a short grace period). */
    private function cappedTimeout(int $timeout, ?float $deadline): int
    {
        if ($deadline === null) {
            return $timeout;
        }

        $left = (int) ceil(max(0.0, $deadline - microtime(true))) + self::DEADLINE_GRACE_SECONDS;

        return $timeout > 0 ? min($timeout, $left) : $left;
    }

    /** Whether a wait of this many seconds ends before the deadline. */
    private function endsBeforeDeadline(float $delay, ?float $deadline): bool
    {
        return $deadline === null || microtime(true) + $delay < $deadline;
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
        $raw = $response->getBody()->getContents();

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ApiException('Invalid API response: malformed JSON', 0, $e, null, null, ErrorText::body($raw));
        }

        if (! is_array($decoded)) {
            throw new ApiException(
                'Invalid API response: unexpected JSON',
                0,
                null,
                null,
                null,
                ErrorText::body($raw),
            );
        }

        return $decoded;
    }

    private function failure(mixed $reason, ?float $fallbackRetryAfter = null): Throwable
    {
        if ($reason instanceof BadResponseException) {
            $response = $reason->getResponse();
            $status = $response->getStatusCode();
            $raw = (string) $response->getBody();
            $body = ErrorText::body($raw);

            if ($status === 429) {
                $retryAfter = $this->retryAfter($response) ?? (int) ceil($fallbackRetryAfter ?? 1.0);

                return new RateLimitException(
                    "Apify API rate limit reached (retry after {$retryAfter} seconds)",
                    $retryAfter,
                    $body,
                );
            }

            $message = ErrorText::http($status, $raw);

            if ($status === 402) {
                [$type, $detail] = ErrorText::parse($raw);

                return new SpendingLimitException(
                    'Apify spending limit reached (HTTP 402)'.($detail !== null ? ': '.$detail : ''),
                    $reason,
                    null,
                    null,
                    $type,
                    $body,
                );
            }

            return new ApiException($message, $status, $reason, null, null, $body);
        }

        if ($reason instanceof GuzzleException) {
            return new ApiException(ErrorText::transport($reason), 0, $reason);
        }

        return $reason instanceof Throwable ? $reason : new ApiException('Apify API request failed');
    }
}
