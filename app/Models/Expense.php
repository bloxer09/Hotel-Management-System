<?php

namespace App\Models;

use App\Services\CashReferenceService;
use App\Support\HotelDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    public const STATUS_PENDING_APPROVAL = 'PENDING_APPROVAL';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_POSTED = 'POSTED';

    public const STATUS_REJECTED = 'REJECTED';

    /**
     * VOIDED is only for an open-shift erroneous posting where the
     * physical cash movement did not occur. Closed-shift history stays POSTED.
     */
    public const STATUS_VOIDED = 'VOIDED';

    public const APPROVAL_THRESHOLD = 1000.00;

    protected $fillable = [
        'reference',
        'expense_date',
        'amount',
        'cash_drawer',
        'notes',
        'expense_category_id',
        'receipt_path',
        'recorded_by',
        'status',
        'shift_session_id',
        'posted_shift_session_id',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'posted_by',
        'posted_at',
        'voided_by',
        'voided_at',
        'void_reason',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'posted_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Expense $expense) {
            if (! $expense->expense_category_id) {
                $expense->expense_category_id = ExpenseCategory::uncategorized()->id;
            }
            if (! $expense->status) {
                $expense->status = self::STATUS_POSTED;
            }
            if (! $expense->reference) {
                $expense->reference = CashReferenceService::nextExpenseReference();
            }
        });
    }

    public static function requiresApproval(float $amount): bool
    {
        return round($amount, 2) > self::APPROVAL_THRESHOLD;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function originShift(): BelongsTo
    {
        return $this->belongsTo(ShiftSession::class, 'shift_session_id');
    }

    public function postedShift(): BelongsTo
    {
        return $this->belongsTo(ShiftSession::class, 'posted_shift_session_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isLegacyUnlinked(): bool
    {
        return $this->posted_shift_session_id === null && $this->shift_session_id === null;
    }

    public function accountingShift(): ?ShiftSession
    {
        if ($this->posted_shift_session_id === null) {
            return null;
        }

        if ($this->relationLoaded('postedShift')) {
            return $this->postedShift;
        }

        return $this->postedShift()->first();
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
            self::STATUS_PENDING_APPROVAL => 'PENDING APPROVAL',
            self::STATUS_APPROVED => 'APPROVED — AWAITING PAYMENT',
            self::STATUS_POSTED => 'POSTED',
            self::STATUS_REJECTED => 'REJECTED',
            self::STATUS_VOIDED => 'VOIDED — ERRONEOUS POSTING',
            default => (string) $this->status,
        };
    }

    public function createdAtDisplay(): ?string
    {
        return HotelDateTime::formatUtcForDisplay($this->created_at);
    }

    public function reviewedAtDisplay(): ?string
    {
        return HotelDateTime::formatUtcForDisplay($this->reviewed_at);
    }

    public function postedAtDisplay(): ?string
    {
        return HotelDateTime::formatUtcForDisplay($this->posted_at);
    }
}
