<?php

declare(strict_types=1);

namespace GlobalNewsScraper\Tests\Support;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Guzzle handler that plays the Apify API. Behaviour is configured per actor, keyed by the `user~name` path slug:
 *
 * - `items`: dataset items (default none)
 * - `statuses`: run statuses returned by the start call and then by each poll (default `['SUCCEEDED']`)
 * - `start`, `poll`, `dataset`: a Response that replaces the default answer of that call
 * - `abort`: a Response that replaces the default answer of the abort call
 * - `datasetId`: null omits `defaultDatasetId` from the run
 */
final class FakeApify
{
    /** @var array<string, array<string, mixed>> */
    private array $polls = [];

    /** @param  array<string, array<string, mixed>>  $actors */
    public function __construct(private readonly array $actors) {}

    /** @return PromiseInterface<Response> */
    public function __invoke(RequestInterface $request): PromiseInterface
    {
        $path = $request->getUri()->getPath();

        if ($request->getMethod() === 'POST' && preg_match('#^/v2/acts/([^/]+)/runs$#', $path, $m) === 1) {
            return Create::promiseFor($this->start($m[1]));
        }

        if ($request->getMethod() === 'POST' && preg_match('#^/v2/actor-runs/run-(.+)/abort$#', $path, $m) === 1) {
            $abort = $this->actors[$m[1]]['abort'] ?? null;

            return Create::promiseFor($abort instanceof Response ? $abort : $this->run($m[1], 'ABORTING'));
        }

        if (preg_match('#^/v2/actor-runs/run-(.+)$#', $path, $m) === 1) {
            return Create::promiseFor($this->poll($m[1]));
        }

        if (preg_match('#^/v2/datasets/ds-(.+)/items$#', $path, $m) === 1) {
            return Create::promiseFor($this->dataset($m[1]));
        }

        return Create::promiseFor(new Response(404, [], '{"error":"unknown route"}'));
    }

    private function start(string $actor): Response
    {
        $config = $this->actors[$actor] ?? [];

        if (($config['start'] ?? null) instanceof Response) {
            return $config['start'];
        }

        $this->polls[$actor] = $config['statuses'] ?? ['SUCCEEDED'];

        return $this->run($actor, array_shift($this->polls[$actor]));
    }

    private function poll(string $actor): Response
    {
        $config = $this->actors[$actor] ?? [];

        if (($config['poll'] ?? null) instanceof Response) {
            return $config['poll'];
        }

        return $this->run($actor, array_shift($this->polls[$actor]) ?? 'SUCCEEDED');
    }

    private function dataset(string $actor): Response
    {
        $config = $this->actors[$actor] ?? [];

        if (($config['dataset'] ?? null) instanceof Response) {
            return $config['dataset'];
        }

        return new Response(200, [], json_encode($config['items'] ?? [], JSON_THROW_ON_ERROR));
    }

    private function run(string $actor, string $status): Response
    {
        $data = ['id' => "run-{$actor}", 'status' => $status];

        if (array_key_exists('datasetId', $this->actors[$actor] ?? []) === false) {
            $data['defaultDatasetId'] = "ds-{$actor}";
        } elseif ($this->actors[$actor]['datasetId'] !== null) {
            $data['defaultDatasetId'] = $this->actors[$actor]['datasetId'];
        }

        return new Response(201, [], json_encode(['data' => $data], JSON_THROW_ON_ERROR));
    }
}
