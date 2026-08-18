<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\PeakDate;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\AuditService;
use App\Support\HotelDateTime;
use DateTime;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class BookingService
{
    const OVERNIGHT_CHECKIN_HOUR = 14;   // 2:00 PM
    const OVERNIGHT_CHECKOUT_HOUR = 12;  // 12:00 PM
    const LATE_CHECKOUT_FEE = 150.00;    // Per hour, rounded up
    const EXTENSION_MIN_HOURS = 1;
    const EXTRA_PAX_FEE_STANDARD = 200.00;
    const EXTRA_PAX_FEE_PEAK = 300.00;

    public static function getShortTimeDurations(): array
    {
        return [3, 6, 12, 24];
    }

    public static function buildOvernightCheckIn($inputDateTime = null): DateTime
    {
        $dt = new DateTime($inputDateTime ?: 'now');
        $dt->setTime(self::OVERNIGHT_CHECKIN_HOUR, 0, 0);
        return $dt;
    }

    public static function isOvernightArrival($checkIn): bool
    {
        $dt = HotelDateTime::fromStay($checkIn);
        $minutes = ((int) $dt->format('H') * 60) + (int) $dt->format('i');

        return $minutes >= (self::OVERNIGHT_CHECKIN_HOUR * 60);
    }

    public static function earlyOvernightMinNightsMessage(): string
    {
        return 'Early check-in Overnight requires at least 2 nights. For a one-day stay, use 24 Hours.';
    }

    public static function minimumOvernightNights($checkIn): int
    {
        return self::isOvernightArrival($checkIn) ? 1 : 2;
    }

    public static function stayTypeMismatchMessage($checkIn, string $bookingType, $numNights = 1): ?string
    {
        if ($bookingType !== 'overnight') {
            return null;
        }

        if ((int) $numNights >= self::minimumOvernightNights($checkIn)) {
            return null;
        }

        return self::earlyOvernightMinNightsMessage();
    }

    public static function rejectInvalidStayType($checkIn, string $bookingType, $numNights = 1): void
    {
        $message = self::stayTypeMismatchMessage($checkIn, $bookingType, $numNights);
        if ($message === null) {
            return;
        }

        throw ValidationException::withMessages([
            'booking_type' => $message,
            'num_nights' => $message,
        ]);
    }

    public static function durationLabel(string $bookingType, $numNights = 1, $shortTimeHours = null, $checkIn = null, $expectedCheckOut = null): string
    {
        if ($bookingType === 'overnight') {
            $nights = max(1, (int) $numNights);

            return $nights === 1 ? '1 night' : $nights.' nights';
        }

        $label = (int) $shortTimeHours.' hours';
        if ($checkIn && $expectedCheckOut && self::isTruncatedShortStay($bookingType, $shortTimeHours, $checkIn, $expectedCheckOut)) {
            return $label.' (paid package)';
        }

        return $label;
    }

    public static function truncatableShortTimeHours(): array
    {
        return [3, 6, 12];
    }

    public static function allowsTruncatedCheckout(string $bookingType, $shortTimeHours): bool
    {
        return $bookingType === 'short_time'
            && in_array((int) $shortTimeHours, self::truncatableShortTimeHours(), true);
    }

    public static function turnoverBufferMinutes(): int
    {
        return max(0, (int) config('hotel.turnover_buffer_minutes', 20));
    }

    public static function isTruncatedShortStay(string $bookingType, $shortTimeHours, $checkIn, $expectedCheckOut): bool
    {
        if (! self::allowsTruncatedCheckout($bookingType, $shortTimeHours) || empty($checkIn) || empty($expectedCheckOut)) {
            return false;
        }

        $standard = self::buildShortTimeExpectedCheckOut($checkIn, $shortTimeHours)->format('Y-m-d H:i:s');
        $actual = HotelDateTime::fromStay($expectedCheckOut)->format('Y-m-d H:i:s');

        return $actual < $standard;
    }

    public static function safeCheckoutCutoff(?string $nextReservedCheckIn): ?string
    {
        if (! $nextReservedCheckIn) {
            return null;
        }

        $cutoff = HotelDateTime::fromStay($nextReservedCheckIn);
        $buffer = self::turnoverBufferMinutes();
        if ($buffer > 0) {
            $cutoff = $cutoff->copy()->subMinutes($buffer);
        }

        return $cutoff->format('Y-m-d H:i:s');
    }

    public static function nextReservedCheckIn(int $roomId, string $afterCheckIn, ?int $excludeBookingId = null, bool $lock = false): ?string
    {
        $query = Booking::query()
            ->where('room_id', $roomId)
            ->whereIn('status', ['active', 'reserved'])
            ->where('check_in', '>', $afterCheckIn)
            ->orderBy('check_in');

        if ($excludeBookingId) {
            $query->where('id', '!=', $excludeBookingId);
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        $next = $query->first();

        return $next?->getRawOriginal('check_in');
    }

    public static function occupyingStay(int $roomId, string $checkIn, ?int $excludeBookingId = null, bool $lock = false): ?Booking
    {
        $query = Booking::query()
            ->where('room_id', $roomId)
            ->whereIn('status', ['active', 'reserved'])
            ->where('check_in', '<=', $checkIn)
            ->where('expected_check_out', '>', $checkIn);

        if ($excludeBookingId) {
            $query->where('id', '!=', $excludeBookingId);
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->orderBy('check_in')->first();
    }

    public static function overlappingStay(int $roomId, string $checkIn, string $checkOut, ?int $excludeBookingId = null, bool $lock = false): ?Booking
    {
        $query = Booking::query()
            ->where('room_id', $roomId)
            ->whereIn('status', ['active', 'reserved'])
            ->where('check_in', '<', $checkOut)
            ->where('expected_check_out', '>', $checkIn);

        if ($excludeBookingId) {
            $query->where('id', '!=', $excludeBookingId);
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->orderBy('check_in')->first();
    }

    /**
     * @return array{available: bool, temporarily_available: bool, next_reserved_check_in: ?string, safe_checkout_cutoff: ?string}
     */
    public static function stayAvailability(
        int $roomId,
        string $checkIn,
        string $standardCheckOut,
        bool $allowTemporaryGap,
        ?int $excludeBookingId = null,
        bool $lock = false
    ): array {
        if (self::occupyingStay($roomId, $checkIn, $excludeBookingId, $lock)) {
            return [
                'available' => false,
                'temporarily_available' => false,
                'next_reserved_check_in' => null,
                'safe_checkout_cutoff' => null,
            ];
        }

        $next = self::nextReservedCheckIn($roomId, $checkIn, $excludeBookingId, $lock);
        $cutoff = self::safeCheckoutCutoff($next);

        if (! $next || $standardCheckOut <= $cutoff) {
            return [
                'available' => true,
                'temporarily_available' => false,
                'next_reserved_check_in' => $next,
                'safe_checkout_cutoff' => $cutoff,
            ];
        }

        if (! $allowTemporaryGap || $checkIn >= $cutoff) {
            return [
                'available' => false,
                'temporarily_available' => false,
                'next_reserved_check_in' => $next,
                'safe_checkout_cutoff' => $cutoff,
            ];
        }

        return [
            'available' => true,
            'temporarily_available' => true,
            'next_reserved_check_in' => $next,
            'safe_checkout_cutoff' => $cutoff,
        ];
    }

    public static function upcomingReservationConflictMessage(): string
    {
        return 'This room now has an upcoming reservation that conflicts with the selected checkout time. Please review the room schedule.';
    }

    public static function validateModifiedCheckout(string $checkIn, string $modifiedCheckOut, string $standardCheckOut, ?string $cutoff): void
    {
        if ($modifiedCheckOut <= $checkIn) {
            throw ValidationException::withMessages([
                'modified_check_out' => 'Modified checkout must be after the actual check-in time.',
            ]);
        }

        if ($modifiedCheckOut > $standardCheckOut) {
            throw ValidationException::withMessages([
                'modified_check_out' => 'Modified checkout cannot be later than the standard package checkout.',
            ]);
        }

        if ($cutoff !== null && $modifiedCheckOut > $cutoff) {
            throw ValidationException::withMessages([
                'modified_check_out' => self::upcomingReservationConflictMessage(),
            ]);
        }
    }

    public static function modifiedCheckoutRemark(string $nextReservedCheckIn, int $shortTimeHours): string
    {
        $arrival = HotelDateTime::fromStay($nextReservedCheckIn)->format('g:i A');

        return "Modified checkout due to incoming reservation at {$arrival}. Charged full {$shortTimeHours}-hour package.";
    }

    public static function rejectOverlappingExtension(Booking $booking, string $newExpectedCheckOut): void
    {
        $overlap = self::overlappingStay(
            (int) $booking->room_id,
            $booking->getRawOriginal('check_in'),
            $newExpectedCheckOut,
            $booking->id
        );

        if ($overlap) {
            throw ValidationException::withMessages([
                'days' => self::upcomingReservationConflictMessage(),
                'hours' => self::upcomingReservationConflictMessage(),
            ]);
        }
    }

    public static function buildOvernightExpectedCheckOut($inputDateTime, $numNights = 1): DateTime
    {
        $numNights = max(1, (int) $numNights);
        $checkIn = HotelDateTime::fromStay($inputDateTime);

        if (! self::isOvernightArrival($checkIn)) {
            $checkOut = $checkIn->copy()->addDays($numNights);

            return new DateTime($checkOut->format('Y-m-d H:i:s'));
        }

        $checkOut = $checkIn->copy()
            ->startOfDay()
            ->addDays($numNights)
            ->setTime(self::OVERNIGHT_CHECKOUT_HOUR, 0, 0);

        return new DateTime($checkOut->format('Y-m-d H:i:s'));
    }

    public static function buildShortTimeExpectedCheckOut($checkInDateTime, $hours): DateTime
    {
        $hours = max(1, (int)$hours);
        $dt = new DateTime($checkInDateTime ?: 'now');
        $dt->modify('+' . $hours . ' hour');
        return $dt;
    }

    public static function getShortTimeRate(RoomType $roomType, $hours): float
    {
        $hours = (int)$hours;
        $map = [
            3  => 'short_time_3h_rate',
            6  => 'short_time_6h_rate',
            12 => 'short_time_12h_rate',
            24 => 'short_time_24h_rate',
        ];

        if (isset($map[$hours]) && $roomType->{$map[$hours]} > 0) {
            return (float)$roomType->{$map[$hours]};
        }

        return round((float)$roomType->hourly_rate * $hours, 2);
    }

    public static function calculateLateCheckoutHours($expectedCheckOut, $actualCheckOut = null): int
    {
        if (empty($expectedCheckOut)) return 0;

        $expected = HotelDateTime::fromStay($expectedCheckOut);
        $actual = $actualCheckOut === null
            ? HotelDateTime::now()
            : HotelDateTime::fromStay($actualCheckOut);

        if ($actual->lte($expected)) return 0;

        $diffSeconds = $actual->getTimestamp() - $expected->getTimestamp();
        return (int) ceil($diffSeconds / 3600);
    }

    public static function calculateLateCheckoutFee($expectedCheckOut, $actualCheckOut = null): float
    {
        return round(self::calculateLateCheckoutHours($expectedCheckOut, $actualCheckOut) * self::LATE_CHECKOUT_FEE, 2);
    }

    public static function isPeakDate($checkIn): ?PeakDate
    {
        $date = date('Y-m-d', strtotime($checkIn));
        $peakDates = \Illuminate\Support\Facades\Cache::remember('active_peak_dates', now()->addMinutes(15), function () {
            return PeakDate::where('is_active', true)->get();
        });

        return $peakDates->first(function ($peak) use ($date) {
            return $peak->date_from <= $date && $peak->date_to >= $date;
        });
    }

    public static function calculateSurcharge(?PeakDate $peakDate, float $baseAmount): float
    {
        if (!$peakDate) return 0.00;
        
        if ($peakDate->surcharge_type === 'percent') {
            return round($baseAmount * ((float)$peakDate->surcharge_amount / 100), 2);
        }
        
        return (float)$peakDate->surcharge_amount;
    }

    public static function calculateBookingAmounts(Room $room, $bookingType, $checkIn, $numNights = 1, $shortTimeHours = 3, $discountType = '', $discountAmount = 0, $numGuests = 1): array
    {
        $roomType = $room->type;
        $numNights = max(1, (int)$numNights);
        $shortTimeHours = (int)$shortTimeHours;
        $numGuests = max(1, (int)$numGuests);

        self::rejectInvalidStayType($checkIn, $bookingType, $numNights);

        if ($bookingType === 'overnight') {
            $baseAmount = round((float)$roomType->base_rate * $numNights, 2);
            $expectedCheckOut = self::buildOvernightExpectedCheckOut($checkIn, $numNights)->format('Y-m-d H:i:s');
        } else {
            if (!in_array($shortTimeHours, self::getShortTimeDurations(), true)) {
                throw new InvalidArgumentException('Invalid short-time duration selected.');
            }
            $baseAmount = self::getShortTimeRate($roomType, $shortTimeHours);
            $expectedCheckOut = self::buildShortTimeExpectedCheckOut($checkIn, $shortTimeHours)->format('Y-m-d H:i:s');
        }

        $peakDate = self::isPeakDate($checkIn);
        $peakSurcharge = self::calculateSurcharge($peakDate, $baseAmount);
        $isPeak = (bool) $peakDate;

        $extraPaxCharges = 0;
        $maxOccupancy = (int)$roomType->max_occupancy;
        if ($numGuests > $maxOccupancy) {
            $extraGuests = $numGuests - $maxOccupancy;
            // Extra guest fee: ₱200/head/night (₱300 during peak dates).
            // Overnight stays charge per night; short-time stays charge a flat fee.
            $feePerHead = $isPeak ? self::EXTRA_PAX_FEE_PEAK : self::EXTRA_PAX_FEE_STANDARD;
            $multiplier = ($bookingType === 'overnight') ? $numNights : 1;
            $extraPaxCharges = $extraGuests * $feePerHead * $multiplier;
        }

        $discountType = trim((string)$discountType);
        $discountAmount = (float)$discountAmount;
        if ($discountType === 'senior' || $discountType === 'pwd') {
            $discountAmount = round(($baseAmount + $peakSurcharge + $extraPaxCharges) * 0.20, 2);
        } elseif ($discountType === 'loyalty') {
            $discountAmount = round(($baseAmount + $peakSurcharge + $extraPaxCharges) * 0.10, 2);
        } elseif ($discountType === 'complimentary') {
            $discountAmount = round($baseAmount + $peakSurcharge + $extraPaxCharges, 2);
        }

        $totalAmount = round(max(0, $baseAmount + $peakSurcharge + $extraPaxCharges - $discountAmount), 2);

        return [
            'base_amount' => $baseAmount,
            'peak_surcharge' => $peakSurcharge,
            'extra_pax_charges' => $extraPaxCharges,
            'discount_amount' => $discountAmount,
            'total_amount' => $totalAmount,
            'expected_check_out' => $expectedCheckOut,
            'is_peak' => $isPeak,
            'peak_label' => $peakDate ? $peakDate->label : null,
        ];
    }

    public static function auditLog($userId, string $action, string $module, ?int $recordId = null, $oldValue = null, $newValue = null, ?string $reason = null): void
    {
        AuditService::log($userId, $action, $module, $recordId, $oldValue, $newValue, $reason);
    }
}
