<?php

declare(strict_types=1);

namespace GlobalNewsScraper\DTO\Enrichment;

use GlobalNewsScraper\Support\Lenient;

/**
 * Result of `withLanguageDetection`, read from `Article::$detectedLanguage`.
 */
final readonly class DetectedLanguage
{
    /**
     * @param  string  $language  Language code of the most likely language, e.g. `en` or `zh-tw`.
     * @param  float|null  $confidence  0 to 1.
     * @param  list<LanguageProbability>  $candidates  All candidate languages, the most likely first.
     */
    public function __construct(
        public string $language,
        public ?float $confidence = null,
        public array $candidates = [],
        public ?string $method = null,
    ) {}

    /**
     * Null when `$data` has no `language` string. Incomplete entries of `all_languages` are left out.
     *
     * @param  array<mixed>  $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $language = Lenient::string($data['language'] ?? null);

        if ($language === null || $language === '') {
            return null;
        }

        $candidates = [];

        foreach (Lenient::array($data['all_languages'] ?? null) ?? [] as $item) {
            $item = Lenient::array($item) ?? [];
            $code = Lenient::string($item['lang'] ?? null);
            $probability = Lenient::float($item['prob'] ?? null);

            if ($code !== null && $probability !== null) {
                $candidates[] = new LanguageProbability($code, $probability);
            }
        }

        return new self(
            language: $language,
            confidence: Lenient::float($data['confidence'] ?? null),
            candidates: $candidates,
            method: Lenient::string($data['method'] ?? null),
        );
    }
}
