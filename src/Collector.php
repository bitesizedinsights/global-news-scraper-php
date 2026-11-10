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
    case Vn = 'vn';
    case Pl = 'pl';
    case Tr = 'tr';
    case It = 'it';
    case De = 'de';
    case Au = 'au';
    case Br = 'br';
    case Ca = 'ca';
    case Ch = 'ch';
    case Gt = 'gt';
    case IdEn = 'id-en';
    case IlEn = 'il-en';
    case InEn = 'in-en';
    case KrEn = 'kr-en';
    case Nl = 'nl';
    case PkEn = 'pk-en';
    case Py = 'py';

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
            self::Vn => 'vietnam-news-scraper',
            self::Pl => 'poland-news-scraper',
            self::Tr => 'turkey-news-scraper',
            self::It => 'italian-news-article-scraper',
            self::De => 'german-news-scraper',
            self::Au => 'australian-news-article-scraper',
            self::Br => 'brazil-news-scraper',
            self::Ca => 'canada-news-scraper',
            self::Ch => 'swiss-news-scraper',
            self::Gt => 'guatemala-news-scraper',
            self::IdEn => 'indonesia-english-news-scraper',
            self::IlEn => 'english-israel-news-scraper',
            self::InEn => 'india-english-news-scraper',
            self::KrEn => 'korean-news-article-scraper',
            self::Nl => 'dutch-news-scraper',
            self::PkEn => 'pakistan-english-news-articles-scraper',
            self::Py => 'paraguay-news-scraper',
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
