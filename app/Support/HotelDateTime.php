<?php

namespace App\Support;

use Carbon\Carbon;
use DateTimeInterface;
use Throwable;

/**
 * Hotel stay timestamps (check_in / check_out / expected_check_out) are
 * Asia/Manila wall-clock values stored as naive datetimes. Shift session
 * bounds and Eloquent created_at values remain UTC (config.app.timezone).
 *
 * Compatibility note (no historical rewrite in this phase):
 * most live BKG rows already store Manila wall-clock digits, while some
 * older activated RES rows still have UTC-style digits. New writes and
 * comparisons use the hotel-local clock going forward.
 */
class HotelDateTime
{
    public const TIMEZONE = 'Asia/Manila';

    public static function now(): Carbon
    {
        return Carbon::now(self::TIMEZONE);
    }

    public static function today(): Carbon
    {
        return static::now()->startOfDay();
    }

    public static function startOfDay(Carbon|string|null $date = null): Carbon
    {
        return static::resolveDate($date)->startOfDay();
    }

    public static function endOfDay(Carbon|string|null $date = null): Carbon
    {
        return static::resolveDate($date)->endOfDay();
    }

    /**
     * Inclusive hotel-local window for a single calendar date.
     *
     * @return array{0: string, 1: string}
     */
    public static function dayWindow(Carbon|string|null $date = null): array
    {
        $day = static::resolveDate($date);

        return [
            $day->copy()->startOfDay()->format('Y-m-d H:i:s'),
            $day->copy()->endOfDay()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Inclusive hotel-local window for a Y-m-d range.
     *
     * @return array{0: string, 1: string}
     */
    public static function dateWindow(string $from, string $to): array
    {
        return [
            static::startOfDay($from)->format('Y-m-d H:i:s'),
            static::endOfDay($to)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Inclusive hotel-local window for a Y-m calendar month.
     *
     * @return array{0: string, 1: string}
     */
    public static function monthWindow(?string $yearMonth = null): array
    {
        try {
            $anchor = $yearMonth
                ? Carbon::createFromFormat('!Y-m', $yearMonth, self::TIMEZONE)
                : static::now()->copy()->startOfMonth();
        } catch (Throwable) {
            $anchor = static::now()->copy()->startOfMonth();
        }

        if (! $anchor instanceof Carbon) {
            $anchor = static::now()->copy()->startOfMonth();
        }

        return [
            $anchor->copy()->startOfMonth()->format('Y-m-d H:i:s'),
            $anchor->copy()->endOfMonth()->format('Y-m-d H:i:s'),
        ];
    }

    public static function parseLocal(?string $value): Carbon
    {
        if ($value === null || trim($value) === '') {
            return static::now();
        }

        $raw = trim($value);
        if (preg_match('/(Z|[+\-]\d{2}:?\d{2})$/i', $raw) === 1) {
            return Carbon::parse($raw)->timezone(self::TIMEZONE);
        }

        $normalized = str_replace('T', ' ', $raw);
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $normalized, self::TIMEZONE);
                if ($parsed instanceof Carbon) {
                    if ($format === 'Y-m-d H:i') {
                        $parsed->setSecond(0);
                    }

                    return $parsed;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return Carbon::parse($normalized, self::TIMEZONE);
    }

    /**
     * Interpret a stay timestamp as Asia/Manila wall-clock.
     *
     * UTC Carbon/DateTime values (including legacy Eloquent datetime casts)
     * are treated as naive digits, not as true UTC instants. Hotel-local
     * Carbons created by this helper are kept as-is.
     */
    public static function fromStay(mixed $value): Carbon
    {
        if ($value instanceof Carbon && $value->timezoneName === self::TIMEZONE) {
            return $value->copy();
        }

        if ($value instanceof DateTimeInterface) {
            return static::parseLocal($value->format('Y-m-d H:i:s'));
        }

        return static::parseLocal($value === null ? null : (string) $value);
    }

    public static function toDatabase(Carbon|string|null $value = null): string
    {
        $dt = $value instanceof Carbon
            ? $value->copy()->timezone(self::TIMEZONE)
            : static::parseLocal($value);

        return $dt->format('Y-m-d H:i:s');
    }

    /**
     * Convert a UTC shift period into hotel-local strings for comparing
     * against check_in / check_out columns.
     *
     * @return array{0: string, 1: string}
     */
    public static function shiftWindow(mixed $start, mixed $end): array
    {
        return [
            Carbon::parse($start)->timezone(self::TIMEZONE)->format('Y-m-d H:i:s'),
            Carbon::parse($end)->timezone(self::TIMEZONE)->format('Y-m-d H:i:s'),
        ];
    }

    private static function resolveDate(Carbon|string|null $date): Carbon
    {
        if ($date instanceof Carbon) {
            if ($date->timezoneName === self::TIMEZONE) {
                return $date->copy();
            }

            return static::parseLocal($date->format('Y-m-d H:i:s'));
        }

        if ($date === null || trim((string) $date) === '') {
            return static::now();
        }

        $raw = trim((string) $date);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            return Carbon::createFromFormat('!Y-m-d', $raw, self::TIMEZONE);
        }

        return static::parseLocal($raw);
    }

    /**
     * Format a true UTC system/audit instant (shift started_at/ended_at,
     * created_at) for Asia/Manila display. Does not rewrite stored digits.
     */
    public static function formatUtcForDisplay(mixed $value): ?string
    {
        $dt = static::asUtcInstant($value);
        if ($dt === null) {
            return null;
        }

        return $dt->timezone(self::TIMEZONE)->format('n/j/Y, g:i:s A');
    }

    /**
     * UTC ISO-8601 instant with Z, for frontend conversion.
     */
    public static function utcIso(mixed $value): ?string
    {
        $dt = static::asUtcInstant($value);
        if ($dt === null) {
            return null;
        }

        return $dt->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Interpret a system timestamp as a UTC instant. Naive "Y-m-d H:i:s"
     * strings are UTC (app timezone), not hotel-local stay fields.
     */
    public static function asUtcInstant(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($value))->utc();
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/(Z|[+\-]\d{2}:?\d{2})$/i', $raw) === 1) {
            return Carbon::parse($raw)->utc();
        }

        $normalized = str_replace('T', ' ', $raw);

        try {
            $parsed = Carbon::createFromFormat('Y-m-d H:i:s', $normalized, 'UTC');
            if ($parsed instanceof Carbon) {
                return $parsed->utc();
            }
        } catch (Throwable) {
            // Fall through to generic parse.
        }

        return Carbon::parse($normalized, 'UTC')->utc();
    }
}
