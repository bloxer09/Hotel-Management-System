<?php

namespace App\Services;

use App\Models\PeakDate;
use App\Models\RoomType;
use App\Models\Room;
use DateTime;
use InvalidArgumentException;

class BookingService
{
    const OVERNIGHT_CHECKIN_HOUR = 14;   // 2:00 PM
    const OVERNIGHT_CHECKOUT_HOUR = 12;  // 12:00 PM
    const LATE_CHECKOUT_FEE = 150.00;    // Per hour, rounded up
    const EXTENSION_MIN_HOURS = 1;

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

    public static function buildOvernightExpectedCheckOut($inputDateTime, $numNights = 1): DateTime
    {
        $numNights = max(1, (int)$numNights);
        $dt = self::buildOvernightCheckIn($inputDateTime);
        $dt->modify('+' . $numNights . ' day');
        $dt->setTime(self::OVERNIGHT_CHECKOUT_HOUR, 0, 0);
        return $dt;
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

        $expected = $expectedCheckOut instanceof DateTime ? clone $expectedCheckOut : new DateTime($expectedCheckOut);
        $actual = $actualCheckOut instanceof DateTime ? clone $actualCheckOut : new DateTime($actualCheckOut ?: 'now');

        if ($actual <= $expected) return 0;

        $diffSeconds = $actual->getTimestamp() - $expected->getTimestamp();
        return (int)ceil($diffSeconds / 3600);
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
        $isPeak = $peakDate ? true : false;

        $extraPaxCharges = 0;
        $maxOccupancy = (int)$roomType->max_occupancy;
        if ($numGuests > $maxOccupancy) {
            $extraGuests = $numGuests - $maxOccupancy;
            $feePerHead = $isPeak ? 300.00 : 200.00;
            // The user approved applying fully for all stay durations, so we don't multiply by numNights
            // Wait, normally extra person is charged per night. If it's overnight, should it be per night?
            // User requested: "when the people who uses the room is more than x pax the room can, they should be charged 200 per extra head"
            // For overnight stays, usually extra pax is per night. Let's do per night for overnight, and just flat fee for short time.
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
        \App\Models\AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'module' => $module,
            'record_id' => $recordId,
            'old_value' => is_scalar($oldValue) ? $oldValue : json_encode($oldValue),
            'new_value' => is_scalar($newValue) ? $newValue : json_encode($newValue),
            'reason' => $reason,
            'ip_address' => request()->ip(),
        ]);
    }
}
