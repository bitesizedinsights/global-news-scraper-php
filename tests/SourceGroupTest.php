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
    expect(Source::WashingtonPost->language())->toBe(SourceGroup::English)
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
        ->and(Source::DongAIlbo->language())->toBe(SourceGroup::Korean)
        ->and(Source::AsahiShimbun->language())->toBe(SourceGroup::Japanese)
        ->and(Source::TuoiTre->language())->toBe(SourceGroup::Vietnamese)
        ->and(Source::Onet->language())->toBe(SourceGroup::Polish)
        ->and(Source::Sabah->language())->toBe(SourceGroup::Turkish)
        ->and(Source::IlGiornale->language())->toBe(SourceGroup::Italian)
        ->and(Source::Bild->language())->toBe(SourceGroup::German)
        ->and(Source::Veja->language())->toBe(SourceGroup::Portuguese)
        // temporarily disabled: pending publication
        // ->and(Source::UltimaHora->language())->toBe(SourceGroup::Spanish)
        ->and(Source::CbcNews->language())->toBe(SourceGroup::English)
        // temporarily disabled: pending publication
        // ->and(Source::Dawn->language())->toBe(SourceGroup::English)
        ->and(Source::Joop->language())->toBe(SourceGroup::Dutch)
        ->and(Source::Ynet->language())->toBe(SourceGroup::Hebrew)
        ->and(Source::AajTak->language())->toBe(SourceGroup::Hindi)
        ->and(Source::Ettoday->language())->toBe(SourceGroup::Chinese)
        ->and(Source::WatsonDe->language())->toBe(SourceGroup::German)
        ->and(Source::WatsonFr->language())->toBe(SourceGroup::French)
        ->and(Source::CorriereDelTicino->language())->toBe(SourceGroup::Italian)
        // temporarily disabled: pending publication
        // ->and(Source::Jang->language())->toBe(SourceGroup::Urdu)
        // ->and(Source::AppPashto->language())->toBe(SourceGroup::Pashto)
        // ->and(Source::AwamiAwaz->language())->toBe(SourceGroup::Sindhi)
        ->and(Source::IlocosTimesIluko->language())->toBe(SourceGroup::Ilocano)
        ->and(Source::BomboRadyoIloilo->language())->toBe(SourceGroup::Hiligaynon)
        ->and(Source::BomboRadyoLegazpi->language())->toBe(SourceGroup::Bikol);
    // temporarily disabled: pending publication
    // ->and(Source::VoaKurdishKurmanji->language())->toBe(SourceGroup::Kurmanji)
    // ->and(Source::VoaKurdishSorani->language())->toBe(SourceGroup::Sorani)
    // ->and(Source::ZazakiNews->language())->toBe(SourceGroup::Zazaki);
});

test('a group plans the collectors that offer its sources', function () {
    $collectors = fn (SourceGroup $group) => array_map(
        fn ($plan) => $plan->collector->value,
        (new Client)->plan($group),
    );

    expect($collectors(SourceGroup::French))->toBe(['fr', 'ch'])
        ->and($collectors(SourceGroup::Swedish))->toBe(['scandinavian'])
        ->and($collectors(SourceGroup::Portuguese))->toBe(['br', 'latam'])
        ->and($collectors(SourceGroup::Thai))->toBe(['th'])
        ->and($collectors(SourceGroup::Korean))->toBe(['kr-ko'])
        ->and($collectors(SourceGroup::Japanese))->toBe(['jp'])
        ->and($collectors(SourceGroup::Vietnamese))->toBe(['vn'])
        ->and($collectors(SourceGroup::Polish))->toBe(['pl'])
        ->and($collectors(SourceGroup::Turkish))->toBe(['tr'])
        ->and($collectors(SourceGroup::Italian))->toBe(['it', 'ch'])
        ->and($collectors(SourceGroup::German))->toBe(['de', 'ch'])
        ->and($collectors(SourceGroup::Dutch))->toBe(['nl'])
        ->and($collectors(SourceGroup::Hebrew))->toBe(['il'])
        ->and($collectors(SourceGroup::Hindi))->toBe(['in'])
        ->and($collectors(SourceGroup::Chinese))->toBe(['tw'])
        // ->and($collectors(SourceGroup::Urdu))->toBe(['pk']) // temporarily disabled: pending publication
        // ->and($collectors(SourceGroup::Pashto))->toBe(['pk']) // temporarily disabled: pending publication
        // ->and($collectors(SourceGroup::Sindhi))->toBe(['pk']) // temporarily disabled: pending publication
        ->and($collectors(SourceGroup::Tagalog))->toBe(['ph'])
        ->and($collectors(SourceGroup::Ilocano))->toBe(['ph'])
        ->and($collectors(SourceGroup::Hiligaynon))->toBe(['ph'])
        ->and($collectors(SourceGroup::Bikol))->toBe(['ph'])
        // ->and($collectors(SourceGroup::Kurmanji))->toBe(['kurdish']) // temporarily disabled: pending publication
        // ->and($collectors(SourceGroup::Sorani))->toBe(['kurdish']) // temporarily disabled: pending publication
        // ->and($collectors(SourceGroup::Zazaki))->toBe(['kurdish']) // temporarily disabled: pending publication
        // with gt and py re-enabled: ['latam', 'mx', 'es', 'gt', 'py']
        ->and($collectors(SourceGroup::Spanish))->toBe(['latam', 'mx', 'es']);
});

// `id-en` offers only outlets that `sea` covers as well, so the planner never needs a run for it.
test('All plans every collector once, except one that is fully covered by another', function () {
    $plans = (new Client)->plan(SourceGroup::All);
    $collectors = array_map(fn ($plan) => $plan->collector, $plans);
    $assigned = array_merge(...array_map(fn ($plan) => $plan->sources, $plans));

    expect($plans)->toHaveCount(count(Collector::cases()) - 1)
        ->and($collectors)->not->toContain(Collector::IdEn)
        ->and(array_unique(array_map(fn ($c) => $c->value, $collectors)))->toHaveCount(count(Collector::cases()) - 1)
        ->and($assigned)->toHaveCount(count(Source::cases()));
});

test('a single enum case is accepted without an array', function () {
    expect((new Client)->plan(Source::BbcNews))->toHaveCount(1)
        ->and((new Client)->plan(Collector::Uk)[0]->sources)->toBe(Collector::Uk->sources())
        ->and((new Client)->plan(SourceGroup::Danish)[0]->collector)->toBe(Collector::Scandinavian);
});

test('sources, groups and collectors can be mixed and are deduplicated', function () {
    $plans = (new Client)->plan(collect([Source::WashingtonPost, Collector::Us, SourceGroup::Danish, Source::Politiken]));
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
