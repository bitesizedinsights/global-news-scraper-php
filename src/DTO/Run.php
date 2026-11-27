<?php

declare(strict_types=1);

namespace GlobalNewsScraper\DTO;

use GlobalNewsScraper\Collector;
use GlobalNewsScraper\RunStatus;
use GlobalNewsScraper\Support\Value;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Handle of one collector run on Apify. Store it (`toArray()`) or its `$id` to look the run up, collect its results
 * or abort it later.
 */
final readonly class Run implements JsonSerializable
{
    /**
     * @param  RunStatus|null  $status  Status at the last answer of Apify; null only when the run was started but the
     *                                  answer held no known status (`Client::getRun()` looks it up).
     * @param  string|null  $statusMessage  The Actor's own status text, raw, capped at 2000 characters.
     * @param  string|null  $lookupError  Why `Client::wait()` could not read the run; the other fields then show the
     *                                    last known state. Null after a successful lookup.
     */
    public function __construct(
        public Collector $collector,
        public string $id,
        public ?RunStatus $status = null,
        public ?string $defaultDatasetId = null,
        public ?string $startedAt = null,
        public ?string $finishedAt = null,
        public ?string $statusMessage = null,
        public ?string $lookupError = null,
    ) {}

    public function withLookupError(string $message): self
    {
        return new self(
            $this->collector,
            $this->id,
            $this->status,
            $this->defaultDatasetId,
            $this->startedAt,
            $this->finishedAt,
            $this->statusMessage,
            $message,
        );
    }

    /** @param  array<mixed>  $data  Output of `toArray()`. */
    public static function fromArray(array $data): self
    {
        $key = Value::string($data, 'collector');
        $status = Value::nullableString($data, 'status');

        return new self(
            collector: Collector::tryFrom($key)
                ?? throw new InvalidArgumentException("Field 'collector' has unknown value '{$key}'"),
            id: Value::string($data, 'id'),
            status: $status === null ? null : (RunStatus::tryFrom($status)
                ?? throw new InvalidArgumentException("Field 'status' has unknown value '{$status}'")),
            defaultDatasetId: Value::nullableString($data, 'defaultDatasetId'),
            startedAt: Value::nullableString($data, 'startedAt'),
            finishedAt: Value::nullableString($data, 'finishedAt'),
            statusMessage: Value::nullableString($data, 'statusMessage'),
            lookupError: Value::nullableString($data, 'lookupError'),
        );
    }

    /** True once the run reached a final status, successful or not. */
    public function isFinished(): bool
    {
        return $this->status?->isFinished() ?? false;
    }

    public function isSuccessful(): bool
    {
        return $this->status === RunStatus::Succeeded;
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'collector' => $this->collector->value,
            'id' => $this->id,
            'status' => $this->status?->value,
            'defaultDatasetId' => $this->defaultDatasetId,
            'startedAt' => $this->startedAt,
            'finishedAt' => $this->finishedAt,
            'statusMessage' => $this->statusMessage,
            'lookupError' => $this->lookupError,
        ];
    }

    /** @return array<string, string|null> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
