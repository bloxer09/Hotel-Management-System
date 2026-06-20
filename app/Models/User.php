<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'username',
        'password',
        'full_name',
        'role',
        'email',
        'phone',
        'avatar_path',
        'is_active',
        'last_login',
    ];

    /**
     * The attributes that should be appended to the model's array form.
     *
     * @var array
     */
    protected $appends = [
        'name',
        'avatar_url',
    ];

    public function getAvatarUrlAttribute()
    {
        return $this->avatar_path ? asset('storage/' . $this->avatar_path) : null;
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login' => 'datetime',
        ];
    }

    public function getNameAttribute()
    {
        return $this->full_name;
    }

    public function setNameAttribute($value)
    {
        $this->attributes['full_name'] = $value;
    }

    public function shiftSessions()
    {
        return $this->hasMany(ShiftSession::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'processed_by');
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }
}
