<?php

declare(strict_types=1);

use GlobalNewsScraper\Collector;
use GlobalNewsScraper\Source;
use GlobalNewsScraper\SourceMap;

test('every source value is a snake case key and unique', function () {
    $values = array_map(fn (Source $s) => $s->value, Source::cases());

    expect($values)->toHaveCount(302)
        ->and(array_unique($values))->toHaveCount(302)
        ->and(array_filter($values, fn (string $v) => preg_match('/^[a-z0-9_]+$/', $v) !== 1))->toBe([]);
});

test('source tryFrom of an unknown key is null', function () {
    expect(Source::tryFrom('not_a_source'))->toBeNull()
        ->and(Source::from('new_york_times'))->toBe(Source::NewYorkTimes)
        ->and(Source::NewYorkTimes->label())->toBe('The New York Times');
});

test('every source has a label and at least one collector', function () {
    foreach (Source::cases() as $source) {
        expect($source->label())->not->toBe('')
            ->and($source->collectors())->not->toBe([]);
    }
});

test('overlapping sources list all their collectors', function () {
    expect(Source::LaJornada->collectors())->toBe([Collector::Latam, Collector::Mx])
        ->and(Source::BbcNews->collectors())->toBe([Collector::Uk]);
});

test('collector sources match the generated map', function () {
    foreach (Collector::cases() as $collector) {
        $values = array_map(fn (Source $s) => $s->value, $collector->sources());

        expect($values)->toBe(SourceMap::BY_COLLECTOR[$collector->value])->and($values)->not->toBe([]);
    }

    expect(Collector::Us->sources())->toContain(Source::Reuters)
        ->and(count(Collector::Us->sources()))->toBe(45);
});
