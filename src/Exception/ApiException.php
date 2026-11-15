<?php

declare(strict_types=1);

namespace GlobalNewsScraper\Exception;

use Exception;
use Throwable;

class ApiException extends Exception
{
    /** @param  string|null  $runId  The Apify run this error is about, when known (e.g. a run that timed out). */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?string $runId = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
