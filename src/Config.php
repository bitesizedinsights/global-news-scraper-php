<?php

declare(strict_types=1);

namespace GlobalNewsScraper;

final readonly class Config
{
    /**
     * @param  int  $timeout  Maximum seconds to wait for each collector run to finish.
     * @param  int  $requestTimeout  Timeout in seconds of a single HTTP request.
     * @param  int  $maxConcurrency  Collector runs executed at the same time (1 or more). The default of 2 keeps
     *                               the load on the shared news backend moderate: every run already fetches
     *                               several articles in parallel.
     */
    public function __construct(
        public string $baseUrl = 'https://api.apify.com/v2',
        public int $timeout = 900,
        public int $requestTimeout = 90,
        public int $maxConcurrency = 2,
    ) {}
}
