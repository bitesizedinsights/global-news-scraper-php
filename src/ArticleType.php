<?php

declare(strict_types=1);

namespace GlobalNewsScraper;

/**
 * Page type of a result. Video and audio pages have little or no text.
 */
enum ArticleType: string
{
    case Article = 'article';
    case Video = 'video';
    case Audio = 'audio';
}
