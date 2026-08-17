<?php

namespace Tests\Unit;

use App\Services\BookingService;
use App\Support\HotelDateTime;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookingServiceLateCheckoutTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[DataProvider('lateCheckoutCases')]
    public function test_late_checkout_hours_use_the_hotel_local_clock(
        string $expectedCheckOut,
        string $actualCheckOut,
        int $expectedHours
    ): void {
        $this->assertSame(
            $expectedHours,
            BookingService::calculateLateCheckoutHours($expectedCheckOut, $actualCheckOut)
        );
        $this->assertSame(
            round($expectedHours * BookingService::LATE_CHECKOUT_FEE, 2),
            BookingService::calculateLateCheckoutFee($expectedCheckOut, $actualCheckOut)
        );
    }

    public static function lateCheckoutCases(): array
    {
        return [
            'before expected checkout' => [
                '2026-08-17 12:00:00',
                '2026-08-17 11:00:00',
                0,
            ],
            'exactly expected checkout' => [
                '2026-08-17 12:00:00',
                '2026-08-17 12:00:00',
                0,
            ],
            'one hour late' => [
                '2026-08-17 12:00:00',
                '2026-08-17 13:00:00',
                1,
            ],
            'one minute into the next hour rounds up' => [
                '2026-08-17 12:00:00',
                '2026-08-17 13:01:00',
                2,
            ],
            'multiple hours late' => [
                '2026-08-17 12:00:00',
                '2026-08-17 16:00:00',
                4,
            ],
        ];
    }

    public function test_default_actual_checkout_uses_hotel_now_not_utc_now(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 05:00:00', 'UTC'));

        $this->assertSame('2026-08-17 13:00:00', HotelDateTime::toDatabase());
        $this->assertSame(
            1,
            BookingService::calculateLateCheckoutHours('2026-08-17 12:00:00')
        );
        $this->assertSame(
            0,
            BookingService::calculateLateCheckoutHours('2026-08-17 13:00:00')
        );
    }
}
