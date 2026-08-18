<?php

namespace Tests\Unit;

use App\Support\HotelDateTime;
use Carbon\Carbon;
use Tests\TestCase;

class HotelDateTimeUtcDisplayTest extends TestCase
{
    public function test_utc_shift_instant_displays_in_asia_manila(): void
    {
        $utc = Carbon::parse('2026-08-18 13:19:34', 'UTC');

        $this->assertSame('8/18/2026, 9:19:34 PM', HotelDateTime::formatUtcForDisplay($utc));
        $this->assertSame('2026-08-18T13:19:34Z', HotelDateTime::utcIso($utc));
        $this->assertSame('2026-08-18 13:19:34', $utc->copy()->utc()->format('Y-m-d H:i:s'));
    }

    public function test_naive_utc_digits_are_not_treated_as_hotel_local_stay_fields(): void
    {
        $this->assertSame(
            '8/18/2026, 9:19:34 PM',
            HotelDateTime::formatUtcForDisplay('2026-08-18 13:19:34')
        );
        $this->assertNotSame(
            '8/18/2026, 1:19:34 PM',
            HotelDateTime::formatUtcForDisplay('2026-08-18 13:19:34')
        );
    }
}
