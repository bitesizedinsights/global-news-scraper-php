<?php

declare(strict_types=1);

namespace GlobalNewsScraper\Support;

use GuzzleHttp\Exception\ConnectException;
use ReflectionClass;
use Throwable;

/**
 * Builds exception messages that never contain a request URL, query string, header or raw response body.
 */
final class ErrorText
{
    public const BODY_LIMIT = 2000;

    public const PART_LIMIT = 500;

    private static function strip(string $text): string
    {
        $text = mb_substr($text, 0, self::BODY_LIMIT);

        return trim((string) preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]+/u', ' ', $text));
    }

    /** Strips control characters and caps the text at 500 characters. */
    public static function clean(string $text): string
    {
        $clean = self::strip($text);

        return mb_strlen($clean) > self::PART_LIMIT ? mb_substr($clean, 0, self::PART_LIMIT - 1).'…' : $clean;
    }

    /** Raw response body for `ApiException::$responseBody`: capped at 2000 characters, control characters stripped. */
    public static function body(string $body): ?string
    {
        $clean = self::strip($body);

        return $clean === '' ? null : $clean;
    }

    /**
     * The Apify error `type` and `message` of a `{"error": {...}}` body (both cleaned, null when missing).
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function parse(string $body): array
    {
        $decoded = json_decode($body, true);
        $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;

        if (! is_array($error)) {
            return [null, null];
        }

        $type = is_string($error['type'] ?? null) ? self::clean($error['type']) : '';
        $message = is_string($error['message'] ?? null) ? self::clean($error['message']) : '';

        return [$type === '' ? null : $type, $message === '' ? null : $message];
    }

    /** `Apify API request failed (HTTP 502): type: message`, or without the detail when the body has none. */
    public static function http(int $status, string $body): string
    {
        [$type, $message] = self::parse($body);
        $detail = $type !== null && $message !== null ? "{$type}: {$message}" : ($message ?? $type);

        return "Apify API request failed (HTTP {$status})".($detail !== null ? ': '.$detail : '');
    }

    /** Generic text for a failure without a response (connect error, timeout, ...), with the exception class name. */
    public static function transport(Throwable $e): string
    {
        $class = (new ReflectionClass($e))->getShortName();
        $timedOut = stripos($e->getMessage(), 'timed out') !== false || stripos($e->getMessage(), 'timeout') !== false;
        $text = match (true) {
            $timedOut => 'timed out',
            $e instanceof ConnectException => 'connection error',
            default => 'transport error',
        };

        return "Apify API request failed: {$text} ({$class})";
    }
}
