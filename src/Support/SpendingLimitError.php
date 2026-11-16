<?php

declare(strict_types=1);

namespace GlobalNewsScraper\Support;

use GlobalNewsScraper\DTO\CollectorError;
use GlobalNewsScraper\Exception\SpendingLimitException;

/**
 * Internal outcome of a collector run stopped by HTTP 402: the CollectorError plus the exception for `scrape()`.
 *
 * @internal
 */
final readonly class SpendingLimitError
{
    public function __construct(
        public CollectorError $error,
        public SpendingLimitException $exception,
    ) {}
}
