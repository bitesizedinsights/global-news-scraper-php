<?php

declare(strict_types=1);

namespace GlobalNewsScraper\Support;

use GlobalNewsScraper\Collector;
use GlobalNewsScraper\DTO\RunPlan;
use GlobalNewsScraper\Source;

/**
 * Maps sources back to collectors with as few runs as possible.
 *
 * @internal
 */
final class Planner
{
    /**
     * Greedy set cover: repeatedly pick the collector that covers the most still uncovered sources. On a tie the
     * country collector wins over a regional one, then the collector with fewer sources (the more specific one).
     * Every source is assigned to exactly one run.
     *
     * @param  list<Source>  $sources
     * @return list<RunPlan>
     */
    public static function plan(array $sources): array
    {
        $uncovered = [];

        foreach ($sources as $source) {
            $uncovered[$source->value] = $source;
        }

        $plans = [];

        while ($uncovered !== []) {
            $best = null;
            $bestCovered = [];

            foreach (Collector::cases() as $collector) {
                $covered = array_values(array_filter(
                    $uncovered,
                    static fn (Source $s): bool => in_array($collector, $s->collectors(), true),
                ));

                if ($covered === [] || ($best !== null && ! self::beats($collector, $covered, $best, $bestCovered))) {
                    continue;
                }

                $best = $collector;
                $bestCovered = $covered;
            }

            if ($best === null) {
                break;
            }

            foreach ($bestCovered as $source) {
                unset($uncovered[$source->value]);
            }

            $plans[] = new RunPlan($best, $bestCovered);
        }

        return $plans;
    }

    /**
     * @param  list<Source>  $covered
     * @param  list<Source>  $bestCovered
     */
    private static function beats(Collector $collector, array $covered, Collector $best, array $bestCovered): bool
    {
        return [-count($covered), $collector->isRegional(), count($collector->sources())]
            < [-count($bestCovered), $best->isRegional(), count($best->sources())];
    }
}
