<?php

declare(strict_types=1);

namespace GlobalNewsScraper\Support;

use GlobalNewsScraper\Collector;
use GlobalNewsScraper\Source;
use GlobalNewsScraper\SourceGroup;
use LogicException;

/**
 * The language of every source, hand-maintained (the collectors' input schemas carry no language). A collector
 * gives its sources a default language; `OVERRIDES` lists the sources that differ and every source of a
 * multi-language collector. Each source has exactly one language. `tests/SourceGroupTest.php` fails when a newly
 * generated source has none.
 *
 * @internal
 */
final class SourceLanguages
{
    /** @var array<string, SourceGroup> */
    private const COLLECTOR_DEFAULTS = [
        'es' => SourceGroup::Spanish,
        'fr' => SourceGroup::French,
        'id' => SourceGroup::Indonesian,
        'jp-en' => SourceGroup::English,
        'latam' => SourceGroup::Spanish,
        'mx' => SourceGroup::Spanish,
        'sea' => SourceGroup::English,
        'uk' => SourceGroup::English,
        'us' => SourceGroup::English,
        'arab' => SourceGroup::Arabic,
        'tw-en' => SourceGroup::English,
        'ng' => SourceGroup::English,
    ];

    /** @var array<string, SourceGroup> */
    private const OVERRIDES = [
        // latam: Brazil
        'folha_de_sao_paulo' => SourceGroup::Portuguese,
        'o_globo' => SourceGroup::Portuguese,
        'estadao' => SourceGroup::Portuguese,
        'g1' => SourceGroup::Portuguese,
        // mx
        'mexico_news_daily' => SourceGroup::English,
        // scandinavian: Denmark
        'berlingske' => SourceGroup::Danish,
        'politiken' => SourceGroup::Danish,
        'jyllands_posten' => SourceGroup::Danish,
        'ekstra_bladet' => SourceGroup::Danish,
        'bt_dk' => SourceGroup::Danish,
        'dr' => SourceGroup::Danish,
        'borsen' => SourceGroup::Danish,
        // scandinavian: Norway
        'vg' => SourceGroup::Norwegian,
        'dagbladet' => SourceGroup::Norwegian,
        'aftenposten' => SourceGroup::Norwegian,
        'nrk' => SourceGroup::Norwegian,
        'nettavisen' => SourceGroup::Norwegian,
        'dagens_naeringsliv' => SourceGroup::Norwegian,
        'bergens_tidende' => SourceGroup::Norwegian,
        'adresseavisen' => SourceGroup::Norwegian,
        // scandinavian: Sweden
        'aftonbladet' => SourceGroup::Swedish,
        'expressen' => SourceGroup::Swedish,
        'dagens_nyheter' => SourceGroup::Swedish,
        'svenska_dagbladet' => SourceGroup::Swedish,
        'svt' => SourceGroup::Swedish,
        'goteborgs_posten' => SourceGroup::Swedish,
        'sydsvenskan' => SourceGroup::Swedish,
        'dagens_industri' => SourceGroup::Swedish,
    ];

    /**
     * @throws LogicException When a source has no language: add it to `OVERRIDES`.
     */
    public static function of(Source $source): SourceGroup
    {
        if (isset(self::OVERRIDES[$source->value])) {
            return self::OVERRIDES[$source->value];
        }

        $collector = $source->collectors()[0] ?? null;

        return ($collector instanceof Collector ? self::COLLECTOR_DEFAULTS[$collector->value] ?? null : null)
            ?? throw new LogicException("Source {$source->value} has no language: add it to ".self::class);
    }
}
