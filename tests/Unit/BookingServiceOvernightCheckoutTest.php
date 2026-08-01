<?php

namespace Tests\Unit;

use App\Services\BookingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BookingServiceOvernightCheckoutTest extends TestCase
{
    #[DataProvider('overnightCheckoutCases')]
    public function test_overnight_checkout_follows_the_early_and_standard_arrival_rules(
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
            '10 AM early arrival receives a full 24 hours' => [
                '2026-08-01 10:00:00',
                1,
                '2026-08-02 10:00:00',
            ],
            'one minute before 2 PM remains an early arrival' => [
                '2026-08-01 13:59:00',
                1,
                '2026-08-02 13:59:00',
            ],
            'exactly 2 PM uses the standard noon checkout' => [
                '2026-08-01 14:00:00',
                1,
                '2026-08-02 12:00:00',
            ],
            '5 PM arrival uses the standard noon checkout' => [
                '2026-08-01 17:00:00',
                1,
                '2026-08-02 12:00:00',
            ],
            'multiple early nights preserve the arrival time' => [
                '2026-08-01 10:00:00',
                3,
                '2026-08-04 10:00:00',
            ],
            'multiple standard nights still end at noon' => [
                '2026-08-01 17:00:00',
                3,
                '2026-08-04 12:00:00',
            ],
        ];
    }

    public function test_short_time_24_hour_checkout_remains_unchanged(): void
    {
        $actualCheckOut = BookingService::buildShortTimeExpectedCheckOut(
            '2026-08-01 10:00:00',
            24
        );

        $this->assertSame('2026-08-02 10:00:00', $actualCheckOut->format('Y-m-d H:i:s'));
    }
}
