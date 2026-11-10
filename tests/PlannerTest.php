<?php

declare(strict_types=1);

use GlobalNewsScraper\Collector;
use GlobalNewsScraper\Source;
use GlobalNewsScraper\Support\Planner;

/**
 * @param  list<Source>  $sources
 * @return array<string, list<string>>
 */
function planSummary(array $sources): array
{
    $summary = [];

    foreach (Planner::plan($sources) as $plan) {
        $summary[$plan->collector->value] = array_map(fn (Source $s) => $s->value, $plan->sources);
    }

    return $summary;
}

test('sources of one collector need a single run', function () {
    expect(planSummary([Source::NewYorkTimes, Source::Reuters]))->toBe(['us' => ['new_york_times', 'reuters']]);
});

test('sources are split over their collectors', function () {
    expect(planSummary([Source::BbcNews, Source::Reuters, Source::TaipeiTimes]))->toBe([
        'tw-en' => ['taipei_times'],
        'uk' => ['bbc_news'],
        'us' => ['reuters'],
    ]);
});

test('a shared source prefers the country collector over the regional one', function () {
    expect(planSummary([Source::LaJornada]))->toBe(['mx' => ['la_jornada']]);
});

test('a shared source joins the collector that is already needed', function () {
    // el_universal_mx exists only in latam; la_jornada is in latam and mx, so one latam run covers both
    expect(planSummary([Source::ElUniversalMx, Source::LaJornada]))
        ->toBe(['latam' => ['el_universal_mx', 'la_jornada']]);
});

test('the collector covering the most sources wins', function () {
    expect(planSummary([Source::ElUniversal, Source::LaJornada, Source::Excelsior]))
        ->toBe(['mx' => ['el_universal', 'la_jornada', 'excelsior']]);
});

test('every source is assigned to exactly one run', function () {
    $plans = Planner::plan(Source::cases());
    $assigned = [];

    foreach ($plans as $plan) {
        foreach ($plan->sources as $source) {
            $assigned[] = $source->value;
            expect($plan->collector->sources())->toContain($source);
        }
    }

    expect($assigned)->toHaveCount(count(Source::cases()))
        ->and(array_unique($assigned))->toHaveCount(count(Source::cases()))
        ->and(count($plans))->toBeLessThanOrEqual(count(Collector::cases()));
});

test('no sources need no runs', function () {
    expect(Planner::plan([]))->toBe([]);
});
