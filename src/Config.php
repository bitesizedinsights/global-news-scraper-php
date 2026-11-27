<?php

declare(strict_types=1);

namespace GlobalNewsScraper;

use InvalidArgumentException;

final readonly class Config
{
    /**
     * @param  int  $timeout  Maximum seconds to wait for each collector run, run start and retries included.
     * @param  int|null  $runTimeout  Apify's own time limit of each collector run in seconds, sent as the `timeout`
     *                                query parameter of the run start (never on polling, abort or dataset requests).
     *                                Null = use `$timeout`, so the platform stops a run when the client has given
     *                                up. 0 = send nothing and keep the Actor's own default. Negative values throw.
     *                                `scrape()` can override it per call.
     * @param  int  $requestTimeout  Timeout in seconds of a single HTTP request.
     * @param  int  $maxConcurrency  Collector runs executed at the same time (1 or more). The default of 2 keeps
     *                               the load on the shared news backend moderate: every run already fetches
     *                               several articles in parallel.
     * @param  int  $maxRetries  How often a failed request is repeated (0 turns retrying off). Rate limits (429),
     *                           server errors (5xx) and connection failures are retried; see the README for the rules.
     * @param  float  $retryBaseDelay  Seconds before the first retry; doubles with each further retry, with jitter.
     * @param  float  $retryMaxDelay  Longest wait before a retry. A `Retry-After` above this is not waited out.
     */
    public function __construct(
        public string $baseUrl = 'https://api.apify.com/v2',
        public int $timeout = 900,
        public int $requestTimeout = 90,
        public int $maxConcurrency = 2,
        public int $maxRetries = 3,
        public float $retryBaseDelay = 1.0,
        public float $retryMaxDelay = 30.0,
        public ?int $runTimeout = null,
    ) {
        if ($runTimeout !== null && $runTimeout < 0) {
            throw new InvalidArgumentException(
                'runTimeout must be a positive number of seconds, or 0 for the Actor default',
            );
        }
    }
}
