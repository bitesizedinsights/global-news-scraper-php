<?php

declare(strict_types=1);

namespace GlobalNewsScraper\DTO;

use GlobalNewsScraper\Collector;
use GlobalNewsScraper\Support\Value;
use InvalidArgumentException;
use JsonSerializable;

/**
 * A collector run that failed. The other collectors' articles are still returned. `runId` is the Apify run when
 * known, so it can be inspected or passed to `Client::abort()`. `statusMessage` is the Actor's own reason of a failed
 * run when it gave one (already part of `message`, capped at 500 characters there).
 */
final readonly class CollectorError implements JsonSerializable
{
    public function __construct(
        public Collector $collector,
        public string $message,
        public ?string $runId = null,
        public ?string $statusMessage = null,
    ) {}

    /** @param  array<mixed>  $data  Output of `toArray()`. */
    public static function fromArray(array $data): self
    {
        $key = Value::string($data, 'collector');

        return new self(
            collector: Collector::tryFrom($key)
                ?? throw new InvalidArgumentException("Field 'collector' has unknown value '{$key}'"),
            message: Value::string($data, 'message'),
            runId: Value::nullableString($data, 'runId'),
            statusMessage: Value::nullableString($data, 'statusMessage'),
        );
    }

    /** @return array{collector: string, message: string, runId?: string, statusMessage?: string} */
    public function toArray(): array
    {
        $data = ['collector' => $this->collector->value, 'message' => $this->message];

        if ($this->runId !== null) {
            $data['runId'] = $this->runId;
        }

        if ($this->statusMessage !== null) {
            $data['statusMessage'] = $this->statusMessage;
        }

        return $data;
    }

    /** @return array{collector: string, message: string, runId?: string, statusMessage?: string} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
