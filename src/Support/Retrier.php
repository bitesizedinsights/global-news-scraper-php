<?php

declare(strict_types=1);

namespace GlobalNewsScraper\Support;

use Closure;
use GlobalNewsScraper\Config;
use GlobalNewsScraper\DTO\RetryEvent;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * The retry rules of the client. Rate limits (429, the request was not processed), server errors (5xx) and connection
 * failures are repeated. Starting a run is billed, so a POST is only repeated after a 429 or a connection failure
 * that shows the request was never sent: after a 5xx or a timeout the run may already exist.
 *
 * A deadline bounds everything: no wait may pass it and each request's timeout is cut to the time left.
 *
 * @internal
 */
final readonly class Retrier
{
    /** Seconds a request may run past the deadline, so a long poll of the remaining time can still answer. */
    private const DEADLINE_GRACE_SECONDS = 5;

    /** cURL errors raised before the request is sent: resolve proxy (5) or host (6), connect (7), TLS (35). */
    private const UNSENT_CURL_ERRORS = [5, 6, 7, 35];

    /**
     * @param  Closure(float): void  $sleeper
     * @param  (Closure(RetryEvent): void)|null  $onRetry
     */
    public function __construct(private Config $config, private Closure $sleeper, private ?Closure $onRetry = null) {}

    /** The per-request timeout, cut to the time left until the deadline (plus a short grace period). */
    public function requestTimeout(int $timeout, ?float $deadline): int
    {
        if ($deadline === null) {
            return $timeout;
        }

        $left = (int) ceil(max(0.0, $deadline - microtime(true))) + self::DEADLINE_GRACE_SECONDS;

        return $timeout > 0 ? min($timeout, $left) : $left;
    }

    /**
     * Decides about a failed request. When it may be repeated, reports the retry, waits and returns true.
     *
     * @param  string  $path  The API path of the request, without the base URL and query.
     * @param  int  $attempt  How many retries this request has had so far.
     * @param  int|null  $maxRetries  Null = `Config::$maxRetries`.
     * @param  bool  $skipped  Set to true when only the deadline prevents the retry.
     */
    public function retry(
        GuzzleException $e,
        string $method,
        string $path,
        int $attempt,
        ?float $deadline = null,
        ?int $maxRetries = null,
        bool &$skipped = false,
    ): bool {
        $maxRetries ??= $this->config->maxRetries;

        if ($attempt >= $maxRetries) {
            return false;
        }

        $status = null;
        $retryAfter = null;

        if ($e instanceof BadResponseException) {
            $status = $e->getResponse()->getStatusCode();

            if ($status !== 429 && ($status < 500 || $method === 'POST')) {
                return false;
            }

            if ($status === 429 || $status === 503) {
                $retryAfter = self::retryAfter($e->getResponse());
            }

            // Not clamped: an earlier retry would ignore the server and most likely be rejected again.
            if ($retryAfter !== null && $retryAfter > $this->config->retryMaxDelay) {
                return false;
            }
        } elseif ($method === 'POST' && ! $this->neverSent($e)) {
            return false;
        }

        // 50 to 100 percent jitter, and never less than the server's Retry-After.
        $delay = max($this->backoff($attempt) * (random_int(500, 1000) / 1000), (float) ($retryAfter ?? 0));

        if ($deadline !== null && microtime(true) + $delay >= $deadline) {
            $skipped = true;

            return false;
        }

        $this->report($e, $method, $path, $attempt + 1, $maxRetries, $delay, $status);
        ($this->sleeper)($delay);

        return true;
    }

    /** Exponential backoff without jitter, capped at `Config::$retryMaxDelay`. */
    public function backoff(int $attempt): float
    {
        return min($this->config->retryMaxDelay, $this->config->retryBaseDelay * (2 ** $attempt));
    }

    /**
     * The `Retry-After` header in whole seconds, from its delay-seconds or HTTP-date form. Null when missing or
     * invalid.
     */
    public static function retryAfter(ResponseInterface $response): ?int
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

        $curlError = $this->curlError($e);

        if ($curlError !== null) {
            return in_array($curlError, self::UNSENT_CURL_ERRORS, true);
        }

        return preg_match('/timed?[ -]?out/i', $e->getMessage()) !== 1;
    }

    private function curlError(GuzzleException $e): ?int
    {
        return preg_match('/^cURL error (\d+):/', $e->getMessage(), $match) === 1 ? (int) $match[1] : null;
    }

    private function report(
        GuzzleException $e,
        string $method,
        string $path,
        int $retry,
        int $maxRetries,
        float $delay,
        ?int $status,
    ): void {
        if ($this->onRetry === null) {
            return;
        }

        // IDs are masked and no exception message is passed on: it holds the full URL, maybe with proxy credentials.
        $event = new RetryEvent(
            $method,
            (string) preg_replace('~^/(acts|actor-runs|datasets)/[^/?]+~', '/$1/{id}', $path),
            $retry,
            $maxRetries,
            $delay,
            $status,
            $status === null ? $this->curlError($e) : null,
        );

        try {
            ($this->onRetry)($event);
        } catch (Throwable) {
            // A failing logger must not break a call whose run is already billing.
        }
    }
}
