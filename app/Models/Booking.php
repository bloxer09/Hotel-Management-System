<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $fillable = [
        'booking_ref',
        'room_id',
        'group_ref',
        'guest_profile_id',
        'guest_name',
        'guest_contact',
        'guest_id_type',
        'guest_id_number',
        'guest_id_image_path',
        'num_guests',
        'booking_type',
        'short_time_hours',
        'check_in',
        'check_out',
        'expected_check_out',
        'status',
        'payment_status',
        'base_amount',
        'peak_surcharge',
        'extra_pax_charges',
        'discount_type',
        'discount_amount',
        'extension_fee',
        'late_checkout_fee',
        'late_hours',
        'total_amount',
        'amount_paid',
        'payment_method',
        'cash_amount',
        'gcash_amount',
        'gcash_ref',
        'is_peak',
        'notes',
        'checked_in_by',
        'checked_out_by',
    ];

    protected $casts = [
        'check_in' => 'datetime',
        'check_out' => 'datetime',
        'expected_check_out' => 'datetime',
        'num_guests' => 'integer',
        'short_time_hours' => 'integer',
        'late_hours' => 'integer',
        'is_peak' => 'boolean',
        'base_amount' => 'float',
        'peak_surcharge' => 'float',
        'discount_amount' => 'float',
        'extension_fee' => 'float',
        'late_checkout_fee' => 'float',
        'total_amount' => 'float',
        'amount_paid' => 'float',
        'cash_amount' => 'float',
        'gcash_amount' => 'float',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }



    public function guestProfile()
    {
        return $this->belongsTo(GuestProfile::class, 'guest_profile_id');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function inventoryUsages()
    {
        return $this->hasMany(InventoryUsage::class);
    }

    public function checkinStaff()
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function checkoutStaff()
    {
        return $this->belongsTo(User::class, 'checked_out_by');
    }

    public function checkedInBy()
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function checkedOutBy()
    {
        return $this->belongsTo(User::class, 'checked_out_by');
    }
}
