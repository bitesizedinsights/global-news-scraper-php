<?php

declare(strict_types=1);

use GlobalNewsScraper\Client;
use GlobalNewsScraper\Collector;
use GlobalNewsScraper\DTO\CollectorError;
use GlobalNewsScraper\DTO\Run;
use GlobalNewsScraper\DTO\RunBatch;
use GlobalNewsScraper\DTO\RunPlan;
use GlobalNewsScraper\Source;
use GlobalNewsScraper\SourceGroup;
use GlobalNewsScraper\SourceMap;

// temporarily disabled: pending publication. Delete a key here when its collector is re-enabled.
dataset('disabled collectors', ['gt', 'kurdish', 'pk', 'pk-en', 'py']);

test('a disabled collector is not offered', function (string $key) {
    expect(Collector::tryFrom($key))->toBeNull()
        ->and(SourceMap::BY_COLLECTOR)->not->toHaveKey($key);
})->with('disabled collectors');

test('stored data of a disabled collector is refused cleanly', function (string $key) {
    $run = ['collector' => $key, 'id' => 'abc123'];
    $message = "Field 'collector' has unknown value '{$key}'";

    expect(fn () => Run::fromArray($run))->toThrow(InvalidArgumentException::class, $message)
        ->and(fn () => RunBatch::fromArray(['runs' => [$run], 'errors' => []]))
        ->toThrow(InvalidArgumentException::class, $message)
        ->and(fn () => RunPlan::fromArray(['collector' => $key, 'sources' => []]))
        ->toThrow(InvalidArgumentException::class, $message)
        ->and(fn () => CollectorError::fromArray(['collector' => $key, 'message' => 'x']))
        ->toThrow(InvalidArgumentException::class, $message);
})->with('disabled collectors');

test('sources shared with a disabled collector stay with the other collectors', function () {
    expect(Source::PrensaLibre->collectors())->toBe([Collector::Latam])
        ->and(Source::AbcColor->collectors())->toBe([Collector::Latam])
        ->and((new Client)->plan([Source::PrensaLibre, Source::AbcColor])[0]->collector)->toBe(Collector::Latam);
});

test('every source group has sources', function () {
    foreach (SourceGroup::cases() as $group) {
        expect($group->sources())->not->toBe([]);
    }
});
