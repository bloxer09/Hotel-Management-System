<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    protected $fillable = [
        'room_number',
        'room_type_id',
        'floor',
        'status',
        'notes',
        'photo_path',
    ];

    protected $appends = [
        'photo_url',
    ];

    public function getPhotoUrlAttribute()
    {
        if ($this->photo_path) {
            return asset('storage/' . $this->photo_path);
        }
        return $this->type ? $this->type->photo_url : null;
    }

    protected $casts = [
        'floor' => 'integer',
        'cleaning_started_at' => 'datetime',
    ];

    public function type()
    {
        return $this->belongsTo(RoomType::class, 'room_type_id');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function activeBooking()
    {
        return $this->hasOne(Booking::class)->ofMany(
            ['id' => 'max'],
            function ($query) {
                $query->where('status', 'active');
            }
        );
    }
}
