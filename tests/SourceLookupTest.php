<?php

declare(strict_types=1);

use GlobalNewsScraper\Source;

test('fromLabel finds a source ignoring case and surplus spaces', function (string $label, Source $source) {
    expect(Source::fromLabel($label))->toBe($source);
})->with([
    ['The New York Times', Source::NewYorkTimes],
    ['the new york times', Source::NewYorkTimes],
    ["  The  New\tYork\u{00A0}Times \n", Source::NewYorkTimes],
    ['EXCÉLSIOR', Source::Excelsior],
    ['BBC News', Source::BbcNews],
]);

test('fromLabel does not guess', function (string $label) {
    expect(Source::fromLabel($label))->toBeNull();
})->with([
    'ambiguous' => ['The Guardian'],
    'ambiguous in another case' => ['el universal'],
    'part of a name' => ['New York Times'],
    'key instead of a name' => ['new_york_times'],
    'accent missing' => ['Excelsior'],
    'unknown' => ['No Such Outlet'],
    'blank' => [' '],
    'empty' => [''],
    'pattern characters' => ['.*'],
    'invalid UTF-8' => ["BBC\xB1"],
]);

test('every source with a unique name is found by its label', function () {
    $counts = array_count_values(array_map(
        static fn (Source $source): string => mb_strtolower($source->label()),
        Source::cases(),
    ));

    foreach (Source::cases() as $source) {
        $unique = $counts[mb_strtolower($source->label())] === 1;

        expect(Source::fromLabel($source->label()))->toBe($unique ? $source : null)
            ->and(Source::search($source->label()))->toContain($source);
    }
});

test('search matches parts of names and keys, exact names first', function () {
    $guardians = Source::search('the guardian');

    expect(array_slice($guardians, 0, 2))->each->toBeInstanceOf(Source::class)
        ->and(array_map(static fn (Source $s): string => $s->label(), array_slice($guardians, 0, 2)))
        ->toBe(['The Guardian', 'The Guardian'])
        ->and(count($guardians))->toBeGreaterThan(2)
        ->and(Source::search('bbc'))->toContain(Source::BbcNews)
        ->and(Source::search('new_york_times'))->toBe([Source::NewYorkTimes])
        ->and(Source::search('  NEW YORK  times ')[0])->toBe(Source::NewYorkTimes)
        ->and(Source::search('célsi'))->toContain(Source::Excelsior);
});

test('search keeps the order of the cases and lists no source twice', function () {
    $positions = array_map(
        static fn (Source $s): int => (int) array_search($s, Source::cases(), true),
        Source::search('news'),
    );
    $sorted = $positions;
    sort($sorted);

    expect($positions)->toBe($sorted)->and(array_unique($positions))->toBe($positions);
});

test('search without a usable query finds nothing', function (string $query) {
    expect(Source::search($query))->toBe([]);
})->with(['', '   ', '.*', '[a-z]', 'no such outlet', "\xB1"]);

test('sources of disabled collectors are not found', function (string $key) {
    expect(Source::tryFrom($key))->toBeNull()
        ->and(Source::search($key))->toBe([]);
})->with(['republica_gt', 'prensa_comunitaria', 'botan_times']);

test('the generator keeps the lookup methods', function () {
    $generator = (string) file_get_contents(__DIR__.'/../scripts/generate-sources.php');

    expect($generator)->toContain('public static function fromLabel(string $label): ?self')
        ->toContain('public static function search(string $query): array')
        ->toContain('Support\\\\SourceLookup;');
});
