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
        'resolution_notes',
        'repaired_by',
        'repaired_at',
        'repair_cost',
        'receipt_reference',
        'receipt_attachment_path',
        'after_repair_attachment_path',
        'verified_by',
        'verified_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'repaired_at' => 'datetime',
        'verified_at' => 'datetime',
        'repair_cost' => 'decimal:2',
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

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
