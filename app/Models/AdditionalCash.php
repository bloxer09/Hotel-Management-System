<?php

namespace App\Models;

use App\Services\CashReferenceService;
use App\Support\HotelDateTime;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdditionalCash extends Model
{
    use HasFactory;

    public const STATUS_POSTED = 'POSTED';

    /**
     * VOIDED is only for an open-shift erroneous posting where the
     * physical cash movement did not occur. Closed-shift history stays POSTED.
     */
    public const STATUS_VOIDED = 'VOIDED';

    protected $table = 'additional_cash';

    protected $fillable = [
        'reference',
        'income_date',
        'amount',
        'cash_drawer',
        'notes',
        'receipt_path',
        'recorded_by',
        'status',
        'shift_session_id',
        'voided_by',
        'voided_at',
        'void_reason',
    ];

    protected $casts = [
        'income_date' => 'date',
        'amount' => 'decimal:2',
        'voided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (AdditionalCash $row) {
            if (! $row->status) {
                $row->status = self::STATUS_POSTED;
            }
            if (! $row->reference) {
                $row->reference = CashReferenceService::nextAdditionalCashReference();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function originShift(): BelongsTo
    {
        return $this->belongsTo(ShiftSession::class, 'shift_session_id');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isLegacyUnlinked(): bool
    {
        return $this->shift_session_id === null;
    }

    public function accountingShift(): ?ShiftSession
    {
        if ($this->shift_session_id === null) {
            return null;
        }

        if ($this->relationLoaded('originShift')) {
            return $this->originShift;
        }

        return $this->originShift()->first();
    }

    public function accountingShiftIsOpen(): bool
    {
        $shift = $this->accountingShift();

        return $shift !== null && $shift->ended_at === null;
    }

    public function accountingShiftIsClosed(): bool
    {
        $shift = $this->accountingShift();

        return $shift !== null && $shift->ended_at !== null;
    }

    public function allowsAccountingVoid(): bool
    {
        return $this->isPosted() && $this->accountingShiftIsOpen();
    }

    public function displayStatus(): string
    {
        return match ($this->status) {
            self::STATUS_POSTED => 'POSTED',
            self::STATUS_VOIDED => 'VOIDED — ERRONEOUS POSTING',
            default => (string) $this->status,
        };
    }

    public function createdAtDisplay(): ?string
    {
        return HotelDateTime::formatUtcForDisplay($this->created_at);
    }
}
