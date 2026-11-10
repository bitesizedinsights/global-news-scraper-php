<?php

declare(strict_types=1);

namespace GlobalNewsScraper;

/**
 * A regional news collector Actor. The value is the collector key that ends up in `Article::$collection`.
 */
enum Collector: string
{
    case Es = 'es';
    case Fr = 'fr';
    case Id = 'id';
    case JpEn = 'jp-en';
    case Latam = 'latam';
    case Mx = 'mx';
    case Sea = 'sea';
    case Uk = 'uk';
    case Us = 'us';
    case Arab = 'arab';
    case TwEn = 'tw-en';
    case Ng = 'ng';
    case Scandinavian = 'scandinavian';
    case Jp = 'jp';
    case KrKo = 'kr-ko';
    case Th = 'th';

    /**
     * Public Apify Actor ID (`username/name`).
     */
    public function actorId(): string
    {
        return 'bite-sized-insights/'.match ($this) {
            self::Es => 'spain-news-scraper',
            self::Fr => 'france-news-scraper',
            self::Id => 'indonesian-news-article-scraper',
            self::JpEn => 'japan-news-scraper',
            self::Latam => 'latam-news-scraper',
            self::Mx => 'mexico-news-scraper',
            self::Sea => 'asean-news-article-scraper',
            self::Uk => 'uk-news-scraper',
            self::Us => 'us-news-scraper',
            self::Arab => 'arabic-news-scraper',
            self::TwEn => 'taiwan-english-news-scraper',
            self::Ng => 'nigerian-news-articles-scraper',
            self::Scandinavian => 'scandinavian-news-scraper',
            self::Jp => 'nippon-news-scraper',
            self::KrKo => 'korean-news-scraper',
            self::Th => 'thailand-news-scraper',
        };
    }

    /**
     * Sources this collector offers.
     *
     * @return list<Source>
     */
    public function sources(): array
    {
        return array_map(Source::from(...), SourceMap::BY_COLLECTOR[$this->value]);
    }

    /**
     * Name of the collector in the source repository, e.g. `news-collector-us`.
     */
    public function actorName(): string
    {
        return 'news-collector-'.$this->value;
    }

    /**
     * Regional collectors (several countries) lose ties against country collectors when sources overlap.
     */
    public function isRegional(): bool
    {
        return match ($this) {
            self::Latam, self::Sea, self::Arab, self::Scandinavian => true,
            default => false,
        };
    }
}
