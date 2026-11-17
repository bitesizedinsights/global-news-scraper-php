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
    case Dutch = 'nl';
    case Hebrew = 'he';
    case Hindi = 'hi';
    /** Traditional Chinese (Taiwan). */
    case Chinese = 'zh';
    case Urdu = 'ur';
    case Pashto = 'ps';
    case Sindhi = 'sd';
    /** Filipino/Tagalog. */
    case Tagalog = 'tl';
    /** Ilocano: ISO 639-3 code, it has no ISO 639-1 code. */
    case Ilocano = 'ilo';
    /** Hiligaynon (Ilonggo): ISO 639-3 code, it has no ISO 639-1 code. */
    case Hiligaynon = 'hil';
    /** Bikol: ISO 639-3 macrolanguage code, it has no ISO 639-1 code. */
    case Bikol = 'bcl';
    /**
     * Kurdish is split by variety, as the Kurdish collector documents it: Kurmanji (Latin script), Sorani (Arabic
     * script) and Zazaki. The ISO 639-1 code `ku` would merge them, so the ISO 639-3 / BCP 47 codes are used.
     */
    case Kurmanji = 'kmr';
    case Sorani = 'ckb';
    /** Zazaki (Kirmancki, Dimli): a language related to Kurdish, covered by the Kurdish collector. */
    case Zazaki = 'zza';

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
