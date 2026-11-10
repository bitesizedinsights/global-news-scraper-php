<?php

declare(strict_types=1);

namespace GlobalNewsScraper;

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

    public function __construct(?Config $config = null, ?ClientInterface $http = null)
    {
        $this->config = $config ?? new Config;
        $this->http = $http ?? new HttpClient;
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
     * @param  array<mixed>|Collection<array-key, mixed>  $sources  At least one `Source` case.
     * @param  string|null  $searchQuery  Keep only articles whose title, text or URL contain this keyword
     *                                    (1 to 100 characters). Filtering may process more articles than it returns.
     *
     * @throws InvalidInputException
     * @throws MissingApiKeyException
     */
    public function scrape(array|Collection $sources, ?string $searchQuery = null): ScrapeResult
    {
        $plans = $this->plan($sources);

        $options = [];

        if ($searchQuery !== null) {
            $options['search_query'] = $this->searchQuery($searchQuery);
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

    /**
     * Show which collector runs `scrape()` would start for these sources. Sends no request.
     *
     * @param  array<mixed>|Collection<array-key, mixed>  $sources  `Source` cases.
     * @return list<RunPlan>
     *
     * @throws InvalidInputException
     */
    public function plan(array|Collection $sources): array
    {
        $list = $sources instanceof Collection ? $sources->all() : $sources;

        if ($list === []) {
            throw new InvalidInputException('At least one source is required');
        }

        $unique = [];

        foreach ($list as $source) {
            if (! $source instanceof Source) {
                throw new InvalidInputException('Sources must be '.Source::class.' cases');
            }

            $unique[$source->value] = $source;
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

        return $this->http
            ->requestAsync($method, rtrim($this->config->baseUrl, '/').$path, $options)
            ->then(
                fn (ResponseInterface $response): array => $this->decode($response),
                fn (mixed $reason): never => throw $this->failure($reason),
            );
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

    private function failure(mixed $reason): Throwable
    {
        if ($reason instanceof BadResponseException) {
            $response = $reason->getResponse();

            if ($response->getStatusCode() === 429) {
                $retryAfter = (int) ($response->getHeaderLine('Retry-After') ?: 60);

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
