<?php

declare(strict_types=1);

namespace GlobalNewsScraper\DTO\Enrichment;

use GlobalNewsScraper\Support\Lenient;

/**
 * Result of `with5w1h`, read from `Article::$fiveW1h`. A question the result does not hold is null.
 */
final readonly class FiveW1h
{
    public function __construct(
        public ?FiveW1hAnswer $who = null,
        public ?FiveW1hAnswer $what = null,
        public ?FiveW1hAnswer $when = null,
        public ?FiveW1hAnswer $where = null,
        public ?FiveW1hAnswer $why = null,
        public ?FiveW1hAnswer $how = null,
        public ?string $model = null,
    ) {}

    /**
     * Null when the `result` object of `$data` holds none of the six questions.
     *
     * @param  array<mixed>  $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $result = Lenient::array($data['result'] ?? null) ?? [];
        $answers = [];

        foreach (['who', 'what', 'when', 'where', 'why', 'how'] as $question) {
            $answer = Lenient::array($result[$question] ?? null);
            $answers[$question] = $answer === null ? null : FiveW1hAnswer::fromArray($answer);
        }

        if (array_filter($answers) === []) {
            return null;
        }

        return new self(...$answers, model: Lenient::string($data['model'] ?? null));
    }
}
