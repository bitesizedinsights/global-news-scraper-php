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
        'jp' => SourceGroup::Japanese,
        'kr-ko' => SourceGroup::Korean,
        'th' => SourceGroup::Thai,
        'vn' => SourceGroup::Vietnamese,
        'pl' => SourceGroup::Polish,
        'tr' => SourceGroup::Turkish,
        'it' => SourceGroup::Italian,
        'de' => SourceGroup::German,
        'au' => SourceGroup::English,
        'br' => SourceGroup::Portuguese,
        'ca' => SourceGroup::English,
        'gt' => SourceGroup::Spanish,
        'id-en' => SourceGroup::English,
        'il' => SourceGroup::Hebrew,
        'il-en' => SourceGroup::English,
        'in' => SourceGroup::Hindi,
        'in-en' => SourceGroup::English,
        'kr-en' => SourceGroup::English,
        'nl' => SourceGroup::Dutch,
        'pk-en' => SourceGroup::English,
        'py' => SourceGroup::Spanish,
        'tw' => SourceGroup::Chinese,
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
        // vn: English-language outlet
        'hue_ngay_nay_en' => SourceGroup::English,
        // th: English-language outlets
        'bangkok_post' => SourceGroup::English,
        'khaosod_english' => SourceGroup::English,
        'thai_enquirer' => SourceGroup::English,
        'prachatai_english' => SourceGroup::English,
        'thaiger' => SourceGroup::English,
        'chiang_rai_times' => SourceGroup::English,
        'pattaya_mail' => SourceGroup::English,
        'pattaya_news' => SourceGroup::English,
        'bangkok_scoop' => SourceGroup::English,
        'thailand_business_news' => SourceGroup::English,
        // ch: German-, French- and Italian-language outlets (no collector default)
        'watson_de' => SourceGroup::German,
        'nau' => SourceGroup::German,
        'suedostschweiz' => SourceGroup::German,
        'inside_paradeplatz' => SourceGroup::German,
        'watson_fr' => SourceGroup::French,
        'le_courrier' => SourceGroup::French,
        'ghi' => SourceGroup::French,
        'corriere_del_ticino' => SourceGroup::Italian,
        'ticinonews' => SourceGroup::Italian,
        'la_regione' => SourceGroup::Italian,
        // pk: Urdu, Pashto, Sindhi and Saraiki outlets (no collector default)
        'jang' => SourceGroup::Urdu,
        'express_urdu' => SourceGroup::Urdu,
        'nawa_i_waqt' => SourceGroup::Urdu,
        'daily_pakistan' => SourceGroup::Urdu,
        'urdupoint' => SourceGroup::Urdu,
        'daily_ausaf' => SourceGroup::Urdu,
        'ary_news_urdu' => SourceGroup::Urdu,
        'aaj_tv' => SourceGroup::Urdu,
        'app_pashto' => SourceGroup::Pashto,
        'app_sindhi' => SourceGroup::Sindhi,
        'awami_awaz' => SourceGroup::Sindhi,
        'app_saraiki' => SourceGroup::Saraiki,
        // ph: English-language outlets (no collector default)
        'inquirer' => SourceGroup::English,
        'philstar' => SourceGroup::English,
        'manila_times' => SourceGroup::English,
        'daily_tribune' => SourceGroup::English,
        'gma_news' => SourceGroup::English,
        'rappler' => SourceGroup::English,
        'interaksyon' => SourceGroup::English,
        'businessworld' => SourceGroup::English,
        'mindanews' => SourceGroup::English,
        'mindanao_times' => SourceGroup::English,
        'panay_news' => SourceGroup::English,
        'techpinas' => SourceGroup::English,
        'unbox_ph' => SourceGroup::English,
        'tiebreaker_times' => SourceGroup::English,
        // ph: Philippine-language outlets
        'pilipino_star_ngayon' => SourceGroup::Tagalog,
        'pang_masa' => SourceGroup::Tagalog,
        'abante_tonite' => SourceGroup::Tagalog,
        'gma_ulat_filipino' => SourceGroup::Tagalog,
        'banat' => SourceGroup::Cebuano,
        'boses_ti_amianan' => SourceGroup::Ilocano,
        'ilocos_times_iluko' => SourceGroup::Ilocano,
        'bombo_radyo_iloilo' => SourceGroup::Hiligaynon,
        'bombo_radyo_bacolod' => SourceGroup::Hiligaynon,
        'bombo_radyo_legazpi' => SourceGroup::Bikol,
        'bombo_radyo_naga' => SourceGroup::Bikol,
        'bombo_radyo_tacloban' => SourceGroup::Waray,
        // kurdish: one entry per outlet; mixed feeds get the first-listed (main) variety (no collector default)
        'botan_times' => SourceGroup::Kurmanji,
        'zazaki_news' => SourceGroup::Zazaki,
        'rudaw' => SourceGroup::Sorani,
        'kurdistan24_english' => SourceGroup::English,
        'kurdistan24_sorani' => SourceGroup::Sorani,
        'hengaw' => SourceGroup::Sorani,
        'kurdistanmedia_kdpi' => SourceGroup::Sorani,
        'voa_kurdish_sorani' => SourceGroup::Sorani,
        'voa_kurdish_kurmanji' => SourceGroup::Kurmanji,
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
