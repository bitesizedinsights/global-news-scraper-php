<?php

declare(strict_types=1);

namespace GlobalNewsScraper;

/**
 * A ready-made set of sources: every source, or every source in one language. Pass it to `Client::scrape()` or
 * `Client::plan()` instead of listing outlets one by one, alone or mixed with `Source` and `Collector` cases.
 *
 * Large groups start many collector runs and are billed per run and per article: check `Client::plan()` first and
 * set a `limit`.
 */
enum SourceGroup: string
{
    /** Every source of every collector: all collectors run once. */
    case All = 'all';
    case English = 'en';
    case Spanish = 'es';
    case French = 'fr';
    case Portuguese = 'pt';
    case Indonesian = 'id';
    case Arabic = 'ar';
    case Danish = 'da';
    case Norwegian = 'no';
    case Swedish = 'sv';
    case Japanese = 'ja';
    case Korean = 'ko';
    case Thai = 'th';
    case Vietnamese = 'vi';
    case Polish = 'pl';
    case Turkish = 'tr';
    case Italian = 'it';
    case German = 'de';

    /**
     * Display name, e.g. `Spanish`.
     */
    public function label(): string
    {
        return $this === self::All ? 'All sources' : $this->name;
    }

    /**
     * The sources in this group, in `Source` order.
     *
     * @return list<Source>
     */
    public function sources(): array
    {
        if ($this === self::All) {
            return Source::cases();
        }

        return array_values(array_filter(
            Source::cases(),
            fn (Source $source): bool => $source->language() === $this,
        ));
    }
}
