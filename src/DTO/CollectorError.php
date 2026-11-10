<?php

declare(strict_types=1);

namespace GlobalNewsScraper\DTO;

use GlobalNewsScraper\Collector;
use GlobalNewsScraper\Support\Value;
use InvalidArgumentException;
use JsonSerializable;

/**
 * A collector run that failed. The other collectors' articles are still returned.
 */
final readonly class CollectorError implements JsonSerializable
{
    public function __construct(
        public Collector $collector,
        public string $message,
    ) {}

    /** @param  array<mixed>  $data  Output of `toArray()`. */
    public static function fromArray(array $data): self
    {
        $key = Value::string($data, 'collector');

        return new self(
            collector: Collector::tryFrom($key)
                ?? throw new InvalidArgumentException("Field 'collector' has unknown value '{$key}'"),
            message: Value::string($data, 'message'),
        );
    }

    /** @return array{collector: string, message: string} */
    public function toArray(): array
    {
        return ['collector' => $this->collector->value, 'message' => $this->message];
    }

    /** @return array{collector: string, message: string} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
