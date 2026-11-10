<?php

declare(strict_types=1);

namespace GlobalNewsScraper\DTO;

use GlobalNewsScraper\Collector;
use GlobalNewsScraper\Source;
use GlobalNewsScraper\Support\Value;
use InvalidArgumentException;
use JsonSerializable;

/**
 * One collector run: the collector Actor and the sources switched on for it.
 */
final readonly class RunPlan implements JsonSerializable
{
    /**
     * @param  list<Source>  $sources
     */
    public function __construct(
        public Collector $collector,
        public array $sources,
    ) {}

    /** @param  array<mixed>  $data  Output of `toArray()`. */
    public static function fromArray(array $data): self
    {
        $key = Value::string($data, 'collector');

        return new self(
            collector: Collector::tryFrom($key)
                ?? throw new InvalidArgumentException("Field 'collector' has unknown value '{$key}'"),
            sources: array_map(
                static fn (string $value): Source => Source::tryFrom($value)
                    ?? throw new InvalidArgumentException("Field 'sources' has unknown value '{$value}'"),
                Value::stringList($data, 'sources'),
            ),
        );
    }

    /** @return array{collector: string, sources: list<string>} */
    public function toArray(): array
    {
        return [
            'collector' => $this->collector->value,
            'sources' => array_map(static fn (Source $source): string => $source->value, $this->sources),
        ];
    }

    /** @return array{collector: string, sources: list<string>} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
