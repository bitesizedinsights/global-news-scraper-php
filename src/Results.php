<?php

declare(strict_types=1);

namespace GlobalNewsScraper;

use GlobalNewsScraper\DTO\Article;
use GlobalNewsScraper\DTO\RunPlan;
use GlobalNewsScraper\DTO\ScrapeResult;
use GlobalNewsScraper\Exception\InvalidInputException;
use Illuminate\Support\Collection;

/**
 * Helpers to count the articles of a `ScrapeResult`, of `Client::scrape()` or `Client::results()`.
 *
 * An article says which collector returned it (`Article::$collection`) and the host of its URL
 * (`Article::$sourceDomain`). It does not name a `Source`, and a host does not identify one: several sources share a
 * host, and the Actors do not publish which hosts belong to a source. So there are no counts per source.
 *
 * All counts are those of the merged result: of articles with the same URL only the one of the earlier run is kept,
 * so a collector can have fewer articles here than its run returned and billed.
 */
final class Results
{
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
