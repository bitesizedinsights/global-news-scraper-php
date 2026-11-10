<?php

declare(strict_types=1);

namespace GlobalNewsScraper\DTO;

use GlobalNewsScraper\Collector;
use GlobalNewsScraper\Source;

/**
 * One collector run: the collector Actor and the sources switched on for it.
 */
final readonly class RunPlan
{
    /**
     * @param  list<Source>  $sources
     */
    public function __construct(
        public Collector $collector,
        public array $sources,
    ) {}
}
