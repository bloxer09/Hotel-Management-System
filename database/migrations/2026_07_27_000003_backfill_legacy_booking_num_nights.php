<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('bookings')
            ->where('booking_type', 'overnight')
            ->whereNull('num_nights')
            ->whereNotNull('check_in')
            ->whereNotNull('expected_check_out')
            ->orderBy('id')
            ->chunkById(100, function ($bookings) {
                foreach ($bookings as $booking) {
                    $nights = max(
                        1,
                        (int) Carbon::parse($booking->check_in)->startOfDay()
                            ->diffInDays(Carbon::parse($booking->expected_check_out)->startOfDay())
                    );

                    DB::table('bookings')
                        ->where('id', $booking->id)
                        ->whereNull('num_nights')
                        ->update(['num_nights' => $nights]);
                }
            });
    }

    public function down(): void
    {
        // This is an accuracy-only data correction. Do not erase corrected
        // stay lengths during rollback because later edits may rely on them.
    }
};
