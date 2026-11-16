<?php

declare(strict_types=1);

namespace GlobalNewsScraper\Exception;

class RateLimitException extends ApiException
{
    public function __construct(
        string $message = 'Rate limit exceeded',
        public readonly int $retryAfter = 60,
        ?string $responseBody = null,
    ) {
        parent::__construct($message, 0, null, null, null, $responseBody);
    }
}
