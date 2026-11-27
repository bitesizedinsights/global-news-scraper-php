<?php

declare(strict_types=1);

namespace GlobalNewsScraper\DTO\Enrichment;

enum SentimentLabel: string
{
    case Positive = 'positive';
    case Neutral = 'neutral';
    case Negative = 'negative';
}
