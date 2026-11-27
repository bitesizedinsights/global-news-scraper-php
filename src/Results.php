<?php

declare(strict_types=1);

namespace GlobalNewsScraper;

use GlobalNewsScraper\DTO\Article;
use GlobalNewsScraper\DTO\RunPlan;
use GlobalNewsScraper\DTO\ScrapeResult;
use GlobalNewsScraper\Exception\InvalidInputException;
use Illuminate\Support\Collection;

/**
 * Helpers to count the articles of a `ScrapeResult`, of `Client::scrape()` or `Client::results()`, and to find the
 * collector runs without an article in it.
 *
 * An article says which collector returned it (`Article::$collection`) and the host of its URL
 * (`Article::$sourceDomain`). It does not name a `Source`, and a host does not identify one: several sources share a
 * host, and the Actors do not publish which hosts belong to a source. So there are no counts per source.
 *
 * The inputs of the index methods are therefore the collector runs, the `RunPlan` list of `Client::plan()` for the
 * sources you passed to `scrape()` or `start()`. Indexes are 1-based positions in that list.
 *
 * All counts are those of the merged result: of articles with the same URL only the one of the earlier run is kept,
 * so a collector can have fewer articles here than its run returned and billed.
 */
final class Results
{
    /**
     * Number of collector runs in the plan; a collector listed twice counts once.
     *
     * @param  array<mixed>|Collection<array-key, mixed>  $plan  `RunPlan` objects, as returned by `Client::plan()`.
     *
     * @throws InvalidInputException when the plan holds anything but `RunPlan` objects
     */
    public static function inputCount(array|Collection $plan): int
    {
        return count(self::runPlans($plan));
    }

    /**
     * 1-based positions in the plan of the collectors without any article in the result, in ascending order: failed
     * runs, successful runs without an article, collectors whose articles are all duplicates of an earlier run, and
     * collectors whose run was not passed to `Client::results()`. A collector with at least one article is not listed,
     * however few it has.
     *
     * @param  array<mixed>|Collection<array-key, mixed>  $plan  As for `inputCount()`.
     * @return list<int>
     *
     * @throws InvalidInputException
     */
    public static function missingIndexes(ScrapeResult $result, array|Collection $plan): array
    {
        $counts = self::countsByCollector($result, $plan);
        $missing = [];
        $position = 0;

        foreach (self::runPlans($plan) as $key => $runPlan) {
            $position++;

            if ($counts[$key] === 0) {
                $missing[] = $position;
            }
        }

        return $missing;
    }

    /**
     * True when every planned collector has at least one article (an empty plan is complete). This is not
     * `ScrapeResult::isComplete()`, which is true when there is no error, even if collectors returned nothing.
     * It does not mean that every source, or every matching article, is in the result.
     *
     * @param  array<mixed>|Collection<array-key, mixed>  $plan  As for `inputCount()`.
     *
     * @throws InvalidInputException
     */
    public static function isComplete(ScrapeResult $result, array|Collection $plan): bool
    {
        return self::missingIndexes($result, $plan) === [];
    }

    /**
     * Planned collectors with a `CollectorError`, in plan order: failed or not started, and with `Client::results()`
     * also unfinished or unreadable runs. If you merged several results yourself, such a collector can have articles.
     *
     * @param  array<mixed>|Collection<array-key, mixed>  $plan  As for `inputCount()`.
     * @return list<Collector>
     *
     * @throws InvalidInputException
     */
    public static function failedCollectors(ScrapeResult $result, array|Collection $plan): array
    {
        $failed = self::failedKeys($result);
        $collectors = [];

        foreach (self::runPlans($plan) as $key => $runPlan) {
            if (isset($failed[$key])) {
                $collectors[] = $runPlan->collector;
            }
        }

        return $collectors;
    }

    /**
     * Planned collectors with neither an article nor a `CollectorError`, in plan order. After `scrape()` their runs
     * succeeded but added nothing: no article, or only duplicates of an earlier run. After `Client::results()` this
     * holds only if you passed it all runs of the plan: a run you left out is listed here too.
     *
     * @param  array<mixed>|Collection<array-key, mixed>  $plan  As for `inputCount()`.
     * @return list<Collector>
     *
     * @throws InvalidInputException
     */
    public static function emptyCollectors(ScrapeResult $result, array|Collection $plan): array
    {
        $counts = self::countsByCollector($result, $plan);
        $failed = self::failedKeys($result);
        $collectors = [];

        foreach (self::runPlans($plan) as $key => $runPlan) {
            if ($counts[$key] === 0 && ! isset($failed[$key])) {
                $collectors[] = $runPlan->collector;
            }
        }

        return $collectors;
    }

    /**
     * Number of articles per collector key (`us`, `jp-en`, ...).
     *
     * With a plan every planned collector is listed, in plan order, also with 0; collectors that have articles but are
     * not in the plan follow. Without a plan only collectors with articles are listed, in the order of the entries.
     *
     * A 0 does not say why: see `ScrapeResult::$errors` for failed runs. A successful run can also have no article,
     * or only articles another collector already returned.
     *
     * @param  array<mixed>|Collection<array-key, mixed>  $plan  `RunPlan` objects, as returned by `Client::plan()`.
     * @return array<string, int>
     *
     * @throws InvalidInputException when the plan holds anything but `RunPlan` objects
     */
    public static function countsByCollector(ScrapeResult $result, array|Collection $plan = []): array
    {
        $counts = [];

        foreach (self::runPlans($plan) as $key => $runPlan) {
            $counts[$key] = 0;
        }

        foreach ($result->entries as $article) {
            $counts[$article->collection] = ($counts[$article->collection] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Number of articles per host of the article URL, in the order of the entries. Hosts are lower-cased, without a
     * leading `www.` or a trailing dot; other subdomains stay separate. Articles without a host are not counted.
     *
     * A host is not a `Source`: one outlet can publish on several hosts, several sources can share one, and a feed
     * can link to another site.
     *
     * @return array<string, int>
     */
    public static function countsByDomain(ScrapeResult $result): array
    {
        $counts = [];

        foreach ($result->entries as $article) {
            $domain = self::domain($article);

            if ($domain !== '') {
                $counts[$domain] = ($counts[$domain] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /** @return array<string, true> Keys of the collectors with an error. */
    private static function failedKeys(ScrapeResult $result): array
    {
        $failed = [];

        foreach ($result->errors as $error) {
            $failed[$error->collector->value] = true;
        }

        return $failed;
    }

    private static function domain(Article $article): string
    {
        $host = rtrim(strtolower(trim($article->sourceDomain)), '.');

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /**
     * The plan by collector key, in order; the sources of a collector listed twice are joined.
     *
     * @param  array<mixed>|Collection<array-key, mixed>  $plan
     * @return array<string, RunPlan>
     */
    private static function runPlans(array|Collection $plan): array
    {
        $plans = [];

        foreach ($plan instanceof Collection ? $plan->all() : $plan as $item) {
            if (! $item instanceof RunPlan) {
                throw new InvalidInputException('The plan must hold RunPlan objects, see Client::plan()');
            }

            $key = $item->collector->value;
            $sources = [];

            foreach ([...(isset($plans[$key]) ? $plans[$key]->sources : []), ...$item->sources] as $source) {
                $sources[$source->value] = $source;
            }

            $plans[$key] = new RunPlan($item->collector, array_values($sources));
        }

        return $plans;
    }
}
