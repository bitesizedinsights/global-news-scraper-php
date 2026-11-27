<?php

declare(strict_types=1);

namespace GlobalNewsScraper\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Strict parser of ISO 8601 dates, the format of the Actors' `date_publish` (Python's `datetime.isoformat()`).
 *
 * @internal
 */
final class ArticleDate
{
    private const PATTERN = '/\A(?<year>\d{4})-(?<month>\d\d)-(?<day>\d\d)'
        .'(?:[T ](?<hour>\d\d):(?<minute>\d\d)(?::(?<second>\d\d)(?:\.(?<fraction>\d{1,9}))?)?'
        .'(?<zone>Z|[+-]\d\d(?::?\d\d)?)?)?\z/';

    /**
     * Null for anything but a complete, existing date, with or without a time. A value with `Z` or an offset keeps
     * it; one without is read as wall-clock time in `$assumedTimeZone` (UTC when null).
     */
    public static function parse(string $value, ?DateTimeZone $assumedTimeZone = null): ?DateTimeImmutable
    {
        if (preg_match(self::PATTERN, $value, $m) !== 1) {
            return null;
        }

        $year = (int) $m['year'];
        $month = (int) $m['month'];
        $day = (int) $m['day'];
        $hour = (int) ($m['hour'] ?? 0);
        $minute = (int) ($m['minute'] ?? 0);
        $second = (int) ($m['second'] ?? 0);

        if (! checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        $zone = $m['zone'] ?? '';
        $timeZone = $assumedTimeZone ?? new DateTimeZone('UTC');

        if ($zone !== '') {
            $offset = self::offset($zone);

            if ($offset === null) {
                return null;
            }

            $timeZone = new DateTimeZone($offset);
        }

        $local = sprintf(
            '%04d-%02d-%02d %02d:%02d:%02d.%s',
            $year,
            $month,
            $day,
            $hour,
            $minute,
            $second,
            str_pad(substr($m['fraction'] ?? '', 0, 6), 6, '0'),
        );

        return new DateTimeImmutable($local, $timeZone);
    }

    /** `Z`, `+HH`, `+HHMM` or `+HH:MM` as `+HH:MM`; null outside of -14:00 to +14:00. */
    private static function offset(string $zone): ?string
    {
        if ($zone === 'Z') {
            return '+00:00';
        }

        $digits = str_pad(str_replace(':', '', substr($zone, 1)), 4, '0');
        $hours = (int) substr($digits, 0, 2);
        $minutes = (int) substr($digits, 2);

        if ($minutes > 59 || $hours * 60 + $minutes > 14 * 60) {
            return null;
        }

        return sprintf('%s%02d:%02d', $zone[0], $hours, $minutes);
    }
}
