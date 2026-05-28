<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomType extends Model
{
    protected $fillable = [
        'type_name',
        'description',
        'base_rate',
        'hourly_rate',
        'short_time_3h_rate',
        'short_time_6h_rate',
        'short_time_12h_rate',
        'short_time_24h_rate',
        'max_occupancy',
        'amenities',
        'photo_path',
    ];

    protected $appends = ['photo_url'];

    protected function getPhotoUrlAttribute()
    {
        return $this->photo_path ? asset('storage/' . $this->photo_path) : null;
    }

    protected $casts = [
        'base_rate' => 'float',
        'hourly_rate' => 'float',
        'short_time_3h_rate' => 'float',
        'short_time_6h_rate' => 'float',
        'short_time_12h_rate' => 'float',
        'short_time_24h_rate' => 'float',
        'max_occupancy' => 'integer',
    ];

    public function rooms()
    {
        return $this->hasMany(Room::class);
    }
}
