<?php

declare(strict_types=1);

namespace GlobalNewsScraper\DTO;

use GlobalNewsScraper\Support\Value;
use Illuminate\Support\Collection;
use JsonSerializable;

/**
 * The collector runs of one `Client::start()` call, in `plan()` order. Store it (`toArray()`) to collect the results
 * later, also in another process.
 */
final readonly class RunBatch implements JsonSerializable
{
    /**
     * @param  Collection<int, Run>  $runs  Runs that exist on Apify. They are billed.
     * @param  Collection<int, CollectorError>  $errors  Collectors for which no run handle was obtained.
     */
    public function __construct(
        public Collection $runs,
        public Collection $errors,
    ) {}

    /** @param  array<mixed>  $data  Output of `toArray()`. */
    public static function fromArray(array $data): self
    {
        $runs = [];
        foreach (Value::array($data, 'runs') as $run) {
            $runs[] = Run::fromArray(is_array($run) ? $run : []);
        }

        $errors = [];
        foreach (Value::array($data, 'errors') as $error) {
            $errors[] = CollectorError::fromArray(is_array($error) ? $error : []);
        }

        return new self(new Collection($runs), new Collection($errors));
    }

    /** @return list<string> */
    public function runIds(): array
    {
        return array_values($this->runs->map(static fn (Run $run): string => $run->id)->all());
    }

    public function hasErrors(): bool
    {
        return $this->errors->isNotEmpty();
    }

    /** True when no run is pending any more. Runs may have failed and collectors may not have started. */
    public function isFinished(): bool
    {
        return $this->runs->every(static fn (Run $run): bool => $run->isFinished());
    }

    /** True when every collector got a run and every run succeeded. */
    public function isSuccessful(): bool
    {
        return $this->errors->isEmpty() && $this->runs->every(static fn (Run $run): bool => $run->isSuccessful());
    }

    /** @return array{runs: list<array<string, string|null>>, errors: list<array<string, string>>} */
    public function toArray(): array
    {
        return [
            'runs' => array_values($this->runs->map(static fn (Run $run): array => $run->toArray())->all()),
            'errors' => array_values($this->errors->map(static fn (CollectorError $e): array => $e->toArray())->all()),
        ];
    }

    /** @return array{runs: list<array<string, string|null>>, errors: list<array<string, string>>} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
