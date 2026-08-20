<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StayAmenityPolicy extends Model
{
    public const STAY_OVERNIGHT = 'overnight';

    public const STAY_SHORT_TIME_24 = 'short_time_24';

    public const STAY_KEYS = [
        self::STAY_OVERNIGHT,
        self::STAY_SHORT_TIME_24,
    ];

    protected $fillable = [
        'stay_key',
        'inventory_item_id',
        'default_quantity',
        'is_active',
    ];

    protected $casts = [
        'default_quantity' => 'integer',
        'is_active' => 'boolean',
    ];

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id')->withTrashed();
    }

    public static function stayKeyFor(?Booking $booking): ?string
    {
        if (! $booking) {
            return null;
        }

        if ($booking->booking_type === 'overnight') {
            return self::STAY_OVERNIGHT;
        }

        if ($booking->booking_type === 'short_time' && (int) $booking->short_time_hours === 24) {
            return self::STAY_SHORT_TIME_24;
        }

        return null;
    }

    public static function stayKeyFromRequest(?string $bookingType, mixed $shortTimeHours): ?string
    {
        if ($bookingType === 'overnight') {
            return self::STAY_OVERNIGHT;
        }

        if ($bookingType === 'short_time' && (int) $shortTimeHours === 24) {
            return self::STAY_SHORT_TIME_24;
        }

        return null;
    }

    public static function stayKeyLabel(string $stayKey): string
    {
        return match ($stayKey) {
            self::STAY_OVERNIGHT => 'Overnight',
            self::STAY_SHORT_TIME_24 => '24 Hours',
            default => $stayKey,
        };
    }
}
