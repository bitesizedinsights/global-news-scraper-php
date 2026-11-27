<?php

declare(strict_types=1);

use GlobalNewsScraper\Collector;
use GlobalNewsScraper\DTO\Article;
use GlobalNewsScraper\DTO\CollectorError;
use GlobalNewsScraper\DTO\ScrapeResult;
use GlobalNewsScraper\Exception\ExportException;
use GlobalNewsScraper\Exception\InvalidInputException;
use GlobalNewsScraper\ExportFormat;
use GlobalNewsScraper\Results;
use Illuminate\Support\Collection;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/gns-export-'.bin2hex(random_bytes(8));
    mkdir($this->directory);
});

afterEach(function () {
    foreach (array_diff((array) scandir($this->directory), ['.', '..']) as $file) {
        $path = $this->directory.'/'.$file;
        is_dir($path) && ! is_link($path) ? rmdir($path) : unlink($path);
    }

    rmdir($this->directory);
});

/** @param  array<string, mixed>  $overrides */
function entry(string $collection, array $overrides = []): Article
{
    return Article::fromArray($overrides + loadFixture('article_enriched'), $collection);
}

function exportable(): ScrapeResult
{
    return new ScrapeResult(
        new Collection([entry('uk'), entry('us', ['url' => 'https://a.test/2', 'type' => 'video'])]),
        new Collection([new CollectorError(Collector::Fr, 'Failed', 'run-1')]),
    );
}

/** @return list<list<string>> */
function readCsv(string $path): array
{
    $handle = fopen($path, 'rb');
    assert(is_resource($handle));
    $rows = [];

    while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
        $rows[] = $row;
    }

    fclose($handle);

    return $rows;
}

/** @return list<string> */
function filesIn(string $directory): array
{
    return array_values(array_diff((array) scandir($directory), ['.', '..']));
}

test('toJson restores an equal result and takes json flags', function () {
    $result = exportable();

    expect(ScrapeResult::fromArray(json_decode($result->toJson(), true)))->toEqual($result)
        ->and($result->toJson())->not->toContain("\n")
        ->and($result->toJson(JSON_PRETTY_PRINT))->toContain("\n");
});

test('invalid UTF-8 never breaks an export', function (ExportFormat $format) {
    $result = new ScrapeResult(new Collection([entry('us', ['text' => "ok \xB1\x31 end"])]), new Collection);
    $path = $this->directory.'/out';

    $result->saveTo($path, $format);

    expect((string) file_get_contents($path))->toMatch('//u')
        ->and($result->toJson())->toContain('ok '.chr(92).'ufffd1 end');
})->with(ExportFormat::cases());

test('data JSON cannot hold throws and writes nothing', function () {
    $result = new ScrapeResult(new Collection([entry('us', ['sentiment' => ['x' => INF]])]), new Collection);

    expect(fn () => $result->saveTo($this->directory.'/out.json'))->toThrow(JsonException::class)
        ->and(filesIn($this->directory))->toBe([]);
});

test('the format follows the extension', function () {
    $result = exportable();

    foreach (['a.json', 'b.JSONL', 'c.ndjson', 'd.csv'] as $file) {
        $result->saveTo($this->directory.'/'.$file);
    }

    $json = json_decode((string) file_get_contents($this->directory.'/a.json'), true);
    $lines = explode("\n", rtrim((string) file_get_contents($this->directory.'/b.JSONL'), "\n"));
    $rows = readCsv($this->directory.'/d.csv');

    expect(ScrapeResult::fromArray($json))->toEqual($result)
        ->and($lines)->toHaveCount(2)
        ->and(Article::fromArray(json_decode($lines[0], true)))->toEqual($result->entries->first())
        ->and(file_get_contents($this->directory.'/c.ndjson'))->toBe(implode("\n", $lines)."\n")
        ->and($rows)->toHaveCount(3)
        ->and($rows[0])->toBe(array_keys($result->entries->first()->toArray()));

    $row = array_combine($rows[0], $rows[1]);

    expect($row['collection'])->toBe('uk')
        ->and($row['article_description'])->toBe('')
        ->and(json_decode($row['authors'], true))->toBe(['Reporter One', 'Reporter Two'])
        ->and(json_decode($row['tags'], true))->toBe($result->entries->first()->tags);
});

test('an empty result gives a CSV header and an empty JSON Lines file', function () {
    $empty = new ScrapeResult(new Collection, new Collection);

    $empty->saveTo($this->directory.'/a.csv');
    $empty->saveTo($this->directory.'/a.jsonl');

    expect(readCsv($this->directory.'/a.csv'))->toHaveCount(1)
        ->and(file_get_contents($this->directory.'/a.jsonl'))->toBe('');
});

test('CSV cells that look like formulas get an apostrophe', function (string $value) {
    $result = new ScrapeResult(new Collection([entry('us', ['article_title' => $value])]), new Collection);

    $result->saveTo($this->directory.'/a.csv');
    $rows = readCsv($this->directory.'/a.csv');

    expect(array_combine($rows[0], $rows[1])['article_title'])->toBe("'".str_replace("\0", '', $value));
})->with([
    '=1+1', '+1', '-1', '@SUM(A1)', "\t=1", "\r=1", "\ntext", ' =1', "\u{00A0}=1", "\u{FEFF}=1", "\0=1",
    '＝1', '＠x', '=HYPERLINK("https://evil.test","x")',
]);

test('harmless CSV cells stay as they are and quotes, delimiters and line breaks survive', function () {
    $text = "Say \"hi\", then; =not a formula\r\nsecond line \\ \" end";
    $result = new ScrapeResult(new Collection([entry('us', ['text' => $text])]), new Collection);

    $result->saveTo($this->directory.'/a.csv');
    $rows = readCsv($this->directory.'/a.csv');

    expect($rows)->toHaveCount(2)
        ->and(array_combine($rows[0], $rows[1])['text'])->toBe($text)
        ->and(file_get_contents($this->directory.'/a.csv'))->toStartWith('"url","collection"');
});

test('an existing file is only replaced with overwrite', function () {
    $path = $this->directory.'/a.json';
    file_put_contents($path, 'keep');
    chmod($path, 0600);

    expect(fn () => exportable()->saveTo($path))->toThrow(ExportException::class, 'already exists')
        ->and(file_get_contents($path))->toBe('keep');

    exportable()->saveTo($path, overwrite: true);
    clearstatcache();

    expect(file_get_contents($path))->toBe(exportable()->toJson()."\n")
        ->and(fileperms($path) & 0777)->toBe(0600)
        ->and(filesIn($this->directory))->toBe(['a.json']);
});

test('a symbolic link is never followed', function () {
    file_put_contents($this->directory.'/target', 'keep');
    symlink($this->directory.'/target', $this->directory.'/link.json');
    symlink($this->directory.'/nowhere', $this->directory.'/dangling.json');

    expect(fn () => exportable()->saveTo($this->directory.'/link.json'))->toThrow(ExportException::class)
        ->and(fn () => exportable()->saveTo($this->directory.'/dangling.json'))->toThrow(ExportException::class);

    exportable()->saveTo($this->directory.'/link.json', overwrite: true);

    expect(is_link($this->directory.'/link.json'))->toBeFalse()
        ->and(file_get_contents($this->directory.'/target'))->toBe('keep')
        ->and(file_exists($this->directory.'/nowhere'))->toBeFalse();
});

test('a failed write leaves no file behind', function () {
    mkdir($this->directory.'/dir.json');

    expect(fn () => exportable()->saveTo($this->directory.'/dir.json', overwrite: true))
        ->toThrow(ExportException::class, 'directory')
        ->and(fn () => exportable()->saveTo($this->directory.'/missing/a.json'))
        ->toThrow(ExportException::class, 'does not exist')
        ->and(filesIn($this->directory))->toBe(['dir.json']);
});

test('only local paths with a known format are accepted', function (string $path) {
    expect(fn () => exportable()->saveTo($path))->toThrow(InvalidInputException::class);
})->with(['', 'a.txt', 'a', 'php://output.json', 'phar://x.phar/a.json', "a\0.json"]);

test('onlyArticles drops video and audio pages and keeps the errors', function () {
    $result = exportable();
    $articles = $result->onlyArticles();

    expect($articles)->not->toBe($result)
        ->and($articles->entries->pluck('collection')->all())->toBe(['uk'])
        ->and($articles->errors->all())->toBe($result->errors->all())
        ->and($result->entries)->toHaveCount(2);

    $articles->errors->pop();
    $articles->entries->pop();

    expect($result->errors)->toHaveCount(1)->and($result->entries)->toHaveCount(2);
});

test('since compares moments and decides about undated articles', function () {
    $dates = [
        'before' => '2026-02-14T23:59:59',
        'exact' => '2026-02-15T00:00:00',
        'zoned before' => '2026-02-15T08:00:00+09:00',
        'zoned after' => '2026-02-14T20:00:00-05:00',
        'none' => null,
        'broken' => 'yesterday',
    ];
    $entries = [];

    foreach ($dates as $name => $date) {
        $entries[] = entry('us', ['url' => "https://a.test/{$name}", 'article_title' => $name,
            'date_publish' => $date]);
    }

    $result = new ScrapeResult(new Collection($entries), new Collection([new CollectorError(Collector::Fr, 'x')]));
    $titles = static fn (ScrapeResult $r): array => $r->entries->pluck('title')->all();
    $tokyo = new DateTimeZone('Asia/Tokyo');

    expect($titles($result->since('2026-02-15')))->toBe(['exact', 'zoned after'])
        ->and($titles($result->since(new DateTime('2026-02-15T00:00:00+00:00'), keepUndated: true)))
        ->toBe(['exact', 'zoned after', 'none', 'broken'])
        ->and($titles($result->since('2026-02-15T00:00:00Z', assumedTimeZone: $tokyo)))->toBe(['zoned after'])
        ->and($result->since('2030-01-01')->errors)->toHaveCount(1)
        ->and($result->entries)->toHaveCount(6);
});

test('since rejects other strings', function (string $since) {
    expect(fn () => exportable()->since($since))->toThrow(InvalidInputException::class);
})->with(['', 'yesterday', '-7 days', '02/03/2026']);

test('groupByCollection gives each collector its articles and errors', function () {
    $result = new ScrapeResult(
        new Collection([entry('us'), entry('uk'), entry('us', ['url' => 'https://a.test/3'])]),
        new Collection([new CollectorError(Collector::Fr, 'a'), new CollectorError(Collector::Uk, 'b')]),
    );
    $groups = $result->groupByCollection();

    expect($groups->keys()->all())->toBe(['us', 'uk', 'fr'])
        ->and($groups->map(fn (ScrapeResult $g): int => $g->entries->count())->all())
        ->toBe(['us' => 2, 'uk' => 1, 'fr' => 0])
        ->and($groups['us']->isComplete())->toBeTrue()
        ->and($groups['uk']->errors->first()?->message)->toBe('b')
        ->and($groups['fr']->errors->first()?->message)->toBe('a')
        ->and(array_filter(Results::countsByCollector($result)))->toBe(['us' => 2, 'uk' => 1])
        ->and((new ScrapeResult(new Collection, new Collection))->groupByCollection()->all())->toBe([]);
});
