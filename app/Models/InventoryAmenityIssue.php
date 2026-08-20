<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryAmenityIssue extends Model
{
    public const CONTEXT_INITIAL = 'initial';

    public const CONTEXT_REFILL = 'refill';

    public const SOURCE_TYPE = 'amenity_issue';

    protected $fillable = [
        'reference',
        'booking_id',
        'room_id',
        'shift_session_id',
        'issued_by',
        'issued_at',
        'issue_context',
        'idempotency_key',
        'notes',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'shift_session_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::deleting(function () {
            return false;
        });
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function shiftSession()
    {
        return $this->belongsTo(ShiftSession::class, 'shift_session_id');
    }

    public function items()
    {
        return $this->hasMany(InventoryAmenityIssueItem::class, 'amenity_issue_id');
    }
}
