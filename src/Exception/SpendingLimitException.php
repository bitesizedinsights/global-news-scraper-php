<?php

declare(strict_types=1);

namespace GlobalNewsScraper\Exception;

use Throwable;

/**
 * Apify answered HTTP 402: the account's monthly usage or spending limit is reached. Never retried.
 */
final class SpendingLimitException extends ApiException
{
    /**
     * @param  string|null  $errorType  The Apify error `type` of the response body (e.g. `not-enough-usage-to-run-paid-actor`).
     */
    public function __construct(
        string $message = 'Apify spending limit reached',
        ?Throwable $previous = null,
        ?string $runId = null,
        ?string $statusMessage = null,
        public readonly ?string $errorType = null,
        ?string $responseBody = null,
    ) {
        parent::__construct($message, 402, $previous, $runId, $statusMessage, $responseBody);
    }
}
