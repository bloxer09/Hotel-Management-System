<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'action',
        'module',
        'record_id',
        'old_value',
        'new_value',
        'reason',
        'ip_address',
    ];

    protected $casts = [
        'record_id' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
