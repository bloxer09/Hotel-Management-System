<?php

namespace Tests\Unit;

use App\Services\BookingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BookingServiceOvernightCheckoutTest extends TestCase
{
    #[DataProvider('overnightCheckoutCases')]
    public function test_overnight_checkout_follows_early_and_standard_arrival_rules(
        string $checkIn,
        int $numNights,
        string $expectedCheckOut
    ): void {
        $actualCheckOut = BookingService::buildOvernightExpectedCheckOut($checkIn, $numNights);

        $this->assertSame($expectedCheckOut, $actualCheckOut->format('Y-m-d H:i:s'));
    }

    public static function overnightCheckoutCases(): array
    {
        return [
            '9 AM + 1 night preserves clock time' => [
                '2026-08-26 09:00:00',
                1,
                '2026-08-27 09:00:00',
            ],
            '9 AM + 2 nights preserves clock time' => [
                '2026-08-26 09:00:00',
                2,
                '2026-08-28 09:00:00',
            ],
            '9 AM + 3 nights preserves clock time' => [
                '2026-08-26 09:00:00',
                3,
                '2026-08-29 09:00:00',
            ],
            '1:30 PM + 2 nights preserves clock time' => [
                '2026-08-26 13:30:00',
                2,
                '2026-08-28 13:30:00',
            ],
            '1:59 PM + 2 nights preserves clock time' => [
                '2026-08-26 13:59:00',
                2,
                '2026-08-28 13:59:00',
            ],
            'exactly 2 PM uses noon the next day' => [
                '2026-08-26 14:00:00',
                1,
                '2026-08-27 12:00:00',
            ],
            '2:01 PM + 2 nights uses noon two days later' => [
                '2026-08-26 14:01:00',
                2,
                '2026-08-28 12:00:00',
            ],
            '4 PM + 2 nights uses noon two days later' => [
                '2026-08-26 16:00:00',
                2,
                '2026-08-28 12:00:00',
            ],
            '6 PM + 3 nights uses noon three days later' => [
                '2026-08-26 18:00:00',
                3,
                '2026-08-29 12:00:00',
            ],
            '11 PM + 1 night uses noon the next day' => [
                '2026-08-26 23:00:00',
                1,
                '2026-08-27 12:00:00',
            ],
        ];
    }

    #[DataProvider('shortTimeCheckoutCases')]
    public function test_short_time_checkout_adds_the_selected_hours(
        string $checkIn,
        int $hours,
        string $expectedCheckOut
    ): void {
        $actualCheckOut = BookingService::buildShortTimeExpectedCheckOut($checkIn, $hours);

        $this->assertSame($expectedCheckOut, $actualCheckOut->format('Y-m-d H:i:s'));
    }

    public static function shortTimeCheckoutCases(): array
    {
        return [
            '3 hours' => ['2026-08-26 09:00:00', 3, '2026-08-26 12:00:00'],
            '6 hours' => ['2026-08-26 09:00:00', 6, '2026-08-26 15:00:00'],
            '12 hours' => ['2026-08-26 09:00:00', 12, '2026-08-26 21:00:00'],
            '24 hours from 9 AM' => ['2026-08-26 09:00:00', 24, '2026-08-27 09:00:00'],
            '24 hours from 1:59 PM' => ['2026-08-26 13:59:00', 24, '2026-08-27 13:59:00'],
        ];
    }

    #[DataProvider('durationLabelCases')]
    public function test_duration_labels_use_backend_stay_fields(
        string $bookingType,
        int $numNights,
        ?int $shortTimeHours,
        string $expected
    ): void {
        $this->assertSame(
            $expected,
            BookingService::durationLabel($bookingType, $numNights, $shortTimeHours)
        );
    }

    public static function durationLabelCases(): array
    {
        return [
            '3 hours' => ['short_time', 1, 3, '3 hours'],
            '6 hours' => ['short_time', 1, 6, '6 hours'],
            '12 hours' => ['short_time', 1, 12, '12 hours'],
            '24 hours is never 1 night' => ['short_time', 1, 24, '24 hours'],
            '1 night' => ['overnight', 1, null, '1 night'],
            '2 nights' => ['overnight', 2, null, '2 nights'],
            '3 nights' => ['overnight', 3, null, '3 nights'],
        ];
    }

    #[DataProvider('earlyOvernightOneNightCases')]
    public function test_overnight_one_night_is_invalid_before_2pm(
        string $checkIn,
        string $bookingType,
        int $numNights,
        bool $allowed
    ): void {
        $message = BookingService::stayTypeMismatchMessage($checkIn, $bookingType, $numNights);

        if ($allowed) {
            $this->assertNull($message);
            return;
        }

        $this->assertSame(
            'Early check-in Overnight requires at least 2 nights. For a one-day stay, use 24 Hours.',
            $message
        );
    }

    public static function earlyOvernightOneNightCases(): array
    {
        return [
            '9 AM + 24 Hours is valid' => ['2026-08-26 09:00:00', 'short_time', 1, true],
            '9 AM + Overnight 1 night is rejected' => ['2026-08-26 09:00:00', 'overnight', 1, false],
            '9 AM + Overnight 2 nights is valid' => ['2026-08-26 09:00:00', 'overnight', 2, true],
            '1:59 PM + Overnight 1 night is rejected' => ['2026-08-26 13:59:00', 'overnight', 1, false],
            '1:59 PM + Overnight 2 nights is valid' => ['2026-08-26 13:59:00', 'overnight', 2, true],
            '2:00 PM + Overnight 1 night is valid' => ['2026-08-26 14:00:00', 'overnight', 1, true],
            '2:01 PM + Overnight 1 night is valid' => ['2026-08-26 14:01:00', 'overnight', 1, true],
        ];
    }
}
