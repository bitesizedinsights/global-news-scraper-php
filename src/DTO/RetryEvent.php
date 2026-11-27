<?php

declare(strict_types=1);

namespace GlobalNewsScraper\DTO;

/**
 * A failed API request the client is about to repeat, passed to the `$onRetry` callback of the `Client`.
 * It is safe to log: it holds no token, query, request or response body, and the IDs in the path are masked.
 */
final readonly class RetryEvent
{
    /**
     * @param  string  $method  HTTP method.
     * @param  string  $path  API path with IDs masked, such as `/actor-runs/{id}`.
     * @param  int  $retry  Number of the coming retry, starting at 1.
     * @param  int  $maxRetries  The most retries of this request.
     * @param  float  $delay  Seconds the client waits before this retry.
     * @param  int|null  $status  HTTP status of the failed request; null without an HTTP error response.
     * @param  int|null  $curlError  cURL error number of a failure without an HTTP status, when known.
     */
    public function __construct(
        public string $method,
        public string $path,
        public int $retry,
        public int $maxRetries,
        public float $delay,
        public ?int $status = null,
        public ?int $curlError = null,
    ) {}

    /** One log line, such as `Retry 1/3 of GET /actor-runs/{id} in 1.0 s after HTTP 503`. */
    public function message(): string
    {
        $cause = match (true) {
            $this->status !== null => "HTTP {$this->status}",
            $this->curlError !== null => "cURL error {$this->curlError}",
            default => 'a connection failure',
        };

        return sprintf(
            'Retry %d/%d of %s %s in %.1f s after %s',
            $this->retry,
            $this->maxRetries,
            $this->method,
            $this->path,
            $this->delay,
            $cause,
        );
    }
}
