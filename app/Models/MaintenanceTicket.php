<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MaintenanceTicket extends Model
{
    protected $fillable = [
        'room_id',
        'reported_by',
        'title',
        'description',
        'priority',
        'status',
        'resolved_at',
        'resolved_by',
        'notes',
        'attachment_path',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function reportedBy()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
