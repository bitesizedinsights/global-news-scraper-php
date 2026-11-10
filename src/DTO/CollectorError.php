<?php

declare(strict_types=1);

namespace GlobalNewsScraper\DTO;

use GlobalNewsScraper\Collector;

/**
 * A collector run that failed. The other collectors' articles are still returned.
 */
final readonly class CollectorError
{
    public function __construct(
        public Collector $collector,
        public string $message,
    ) {}
}
