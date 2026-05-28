<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GuestProfile extends Model
{
    protected $fillable = [
        'full_name',
        'contact_number',
        'id_type',
        'id_number',
        'email',
        'address',
        'is_vip',
        'vip_notes',
        'total_stays',
        'total_spent',
        'last_visit',
    ];

    protected $casts = [
        'is_vip' => 'boolean',
        'total_stays' => 'integer',
        'total_spent' => 'float',
        'last_visit' => 'date',
    ];

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}
