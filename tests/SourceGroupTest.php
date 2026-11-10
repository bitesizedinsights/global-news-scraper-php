<?php

declare(strict_types=1);

use GlobalNewsScraper\Client;
use GlobalNewsScraper\Collector;
use GlobalNewsScraper\Exception\InvalidInputException;
use GlobalNewsScraper\Source;
use GlobalNewsScraper\SourceGroup;
use GlobalNewsScraper\SourceMap;

test('every source has exactly one language that is not All', function () {
    foreach (Source::cases() as $source) {
        expect($source->language())->not->toBe(SourceGroup::All);
    }
});

test('the language groups partition the sources', function () {
    $seen = [];

    foreach (SourceGroup::cases() as $group) {
        if ($group === SourceGroup::All) {
            continue;
        }

        expect($group->sources())->not->toBe([]);

        foreach ($group->sources() as $source) {
            expect($seen)->not->toHaveKey($source->value);
            $seen[$source->value] = true;
        }
    }

    expect($seen)->toHaveCount(count(Source::cases()));
});

test('All contains every source', function () {
    expect(SourceGroup::All->sources())->toBe(Source::cases())
        ->and(SourceGroup::All->label())->toBe('All sources')
        ->and(SourceGroup::Spanish->label())->toBe('Spanish');
});

test('languages are assigned as documented', function () {
    expect(Source::Reuters->language())->toBe(SourceGroup::English)
        ->and(Source::Rappler->language())->toBe(SourceGroup::English)
        ->and(Source::MexicoNewsDaily->language())->toBe(SourceGroup::English)
        ->and(Source::LaJornada->language())->toBe(SourceGroup::Spanish)
        ->and(Source::Clarin->language())->toBe(SourceGroup::Spanish)
        ->and(Source::G1->language())->toBe(SourceGroup::Portuguese)
        ->and(Source::LaCroix->language())->toBe(SourceGroup::French)
        ->and(Source::Detik->language())->toBe(SourceGroup::Indonesian)
        ->and(Source::AlJazeera->language())->toBe(SourceGroup::Arabic)
        ->and(Source::Politiken->language())->toBe(SourceGroup::Danish)
        ->and(Source::Nrk->language())->toBe(SourceGroup::Norwegian)
        ->and(Source::Svt->language())->toBe(SourceGroup::Swedish)
        ->and(Source::Matichon->language())->toBe(SourceGroup::Thai)
        ->and(Source::BangkokPost->language())->toBe(SourceGroup::English)
        ->and(Source::ChosunIlbo->language())->toBe(SourceGroup::Korean)
        ->and(Source::AsahiShimbun->language())->toBe(SourceGroup::Japanese)
        ->and(Source::TuoiTre->language())->toBe(SourceGroup::Vietnamese)
        ->and(Source::HueNgayNayEn->language())->toBe(SourceGroup::English)
        ->and(Source::Onet->language())->toBe(SourceGroup::Polish)
        ->and(Source::Sabah->language())->toBe(SourceGroup::Turkish)
        ->and(Source::IlGiornale->language())->toBe(SourceGroup::Italian)
        ->and(Source::Bild->language())->toBe(SourceGroup::German);
});

test('a group plans the collectors that offer its sources', function () {
    $collectors = fn (SourceGroup $group) => array_map(
        fn ($plan) => $plan->collector->value,
        (new Client)->plan($group),
    );

    expect($collectors(SourceGroup::French))->toBe(['fr'])
        ->and($collectors(SourceGroup::Swedish))->toBe(['scandinavian'])
        ->and($collectors(SourceGroup::Portuguese))->toBe(['latam'])
        ->and($collectors(SourceGroup::Thai))->toBe(['th'])
        ->and($collectors(SourceGroup::Korean))->toBe(['kr-ko'])
        ->and($collectors(SourceGroup::Japanese))->toBe(['jp'])
        ->and($collectors(SourceGroup::Vietnamese))->toBe(['vn'])
        ->and($collectors(SourceGroup::Polish))->toBe(['pl'])
        ->and($collectors(SourceGroup::Turkish))->toBe(['tr'])
        ->and($collectors(SourceGroup::Italian))->toBe(['it'])
        ->and($collectors(SourceGroup::German))->toBe(['de']);
});

test('All plans every collector once', function () {
    $plans = (new Client)->plan(SourceGroup::All);
    $collectors = array_map(fn ($plan) => $plan->collector, $plans);
    $assigned = array_merge(...array_map(fn ($plan) => $plan->sources, $plans));

    expect($plans)->toHaveCount(count(Collector::cases()))
        ->and(array_unique(array_map(fn ($c) => $c->value, $collectors)))->toHaveCount(count(Collector::cases()))
        ->and($assigned)->toHaveCount(count(Source::cases()));
});

test('a single enum case is accepted without an array', function () {
    expect((new Client)->plan(Source::BbcNews))->toHaveCount(1)
        ->and((new Client)->plan(Collector::Uk)[0]->sources)->toBe(Collector::Uk->sources())
        ->and((new Client)->plan(SourceGroup::Danish)[0]->collector)->toBe(Collector::Scandinavian);
});

test('sources, groups and collectors can be mixed and are deduplicated', function () {
    $plans = (new Client)->plan(collect([Source::Reuters, Collector::Us, SourceGroup::Danish, Source::Politiken]));
    $byCollector = [];

    foreach ($plans as $plan) {
        $byCollector[$plan->collector->value] = array_map(fn ($s) => $s->value, $plan->sources);
    }

    expect($plans)->toHaveCount(2)
        ->and($byCollector['us'])->toHaveCount(count(Collector::Us->sources()))
        ->and($byCollector['scandinavian'])->toHaveCount(7)
        ->and($byCollector['scandinavian'])->toContain('politiken');
});

test('a group can be filtered before it is passed on', function () {
    $sources = collect(SourceGroup::English->sources())->reject(fn (Source $s) => $s->collectors() === [Collector::Us]);

    expect((new Client)->plan($sources))->not->toContain(Collector::Us)
        ->and(array_map(fn ($p) => $p->collector, (new Client)->plan($sources)))->not->toContain(Collector::Us);
});

test('an invalid selection throws', function () {
    expect(fn () => (new Client)->plan([SourceGroup::English, 'en']))
        ->toThrow(InvalidInputException::class, 'Sources must be Source, SourceGroup or Collector cases')
        ->and(fn () => (new Client)->plan([]))->toThrow(InvalidInputException::class, 'At least one source');
});

test('scrape runs a language group and switches on only its sources', function () {
    $history = [];
    $actor = 'bite-sized-insights~scandinavian-news-scraper';
    $result = makeClient([$actor => ['items' => [article('https://a.test/se')]]], $history)
        ->scrape(SourceGroup::Swedish, limit: 5);

    $body = sentBody($history[0]['request']);

    expect($result->entries)->toHaveCount(1)
        ->and(array_keys(array_filter($body, 'is_bool')))->toBe(SourceMap::BY_COLLECTOR['scandinavian'])
        ->and(array_keys(array_filter($body, fn ($v) => $v === true)))->toBe(
            array_map(fn (Source $s) => $s->value, SourceGroup::Swedish->sources()),
        )
        ->and($body['limit'])->toBe(5);
});
