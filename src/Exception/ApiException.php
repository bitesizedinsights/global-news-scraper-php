<?php

declare(strict_types=1);

namespace GlobalNewsScraper\Exception;

use Exception;
use Throwable;

class ApiException extends Exception
{
    /**
     * @param  string|null  $runId  The Apify run this error is about, when known (e.g. a run that timed out).
     * @param  string|null  $statusMessage  The Actor's own reason (`statusMessage`) of a failed run, when known.
     * @param  string|null  $responseBody  Raw response body (control characters stripped, capped at 2000 characters).
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?string $runId = null,
        public readonly ?string $statusMessage = null,
        public readonly ?string $responseBody = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
