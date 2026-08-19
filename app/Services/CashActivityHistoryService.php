<?php

namespace App\Services;

use App\Models\AdditionalCash;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Support\HotelDateTime;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CashActivityHistoryService
{
    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $expenses = Expense::query()
            ->with(['user:id,full_name', 'reviewer:id,full_name', 'originShift:id,shift_code,ended_at', 'postedShift:id,shift_code,ended_at'])
            ->get()
            ->map(fn (Expense $row) => $this->expenseRow($row));

        $additional = AdditionalCash::query()
            ->with(['user:id,full_name', 'originShift:id,shift_code,ended_at'])
            ->get()
            ->map(fn (AdditionalCash $row) => $this->additionalCashRow($row));

        $rows = $expenses->concat($additional)->sortByDesc(function (array $row) {
            return $row['created_at'] ?? '';
        })->values();

        if (! empty($filters['type']) && in_array($filters['type'], ['expense', 'additional_cash'], true)) {
            $rows = $rows->where('type_key', $filters['type'])->values();
        }
        if (! empty($filters['status'])) {
            $rows = $rows->where('status', $filters['status'])->values();
        }
        if (! empty($filters['search'])) {
            $term = mb_strtolower((string) $filters['search']);
            $rows = $rows->filter(function (array $row) use ($term) {
                return str_contains(mb_strtolower((string) $row['reference']), $term)
                    || str_contains(mb_strtolower((string) $row['reason']), $term)
                    || str_contains(mb_strtolower((string) $row['recorded_by_name']), $term);
            })->values();
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = 20;
        $slice = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $slice,
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function expenseDetail(Expense $expense): array
    {
        $expense->load(['user', 'category', 'originShift.user', 'postedShift.user', 'reviewer', 'poster', 'voider']);

        return [
            ...$this->expenseRow($expense),
            'receipt_path' => $expense->receipt_path,
            'review_notes' => $expense->review_notes,
            'void_reason' => $expense->void_reason,
            'reviewed_at' => HotelDateTime::utcIso($expense->reviewed_at),
            'reviewed_at_display' => $expense->reviewedAtDisplay(),
            'posted_at' => HotelDateTime::utcIso($expense->posted_at),
            'posted_at_display' => $expense->postedAtDisplay(),
            'timeline' => $this->timeline('expenses', (int) $expense->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function additionalCashDetail(AdditionalCash $row): array
    {
        $row->load(['user', 'originShift.user', 'voider']);

        return [
            ...$this->additionalCashRow($row),
            'receipt_path' => $row->receipt_path,
            'void_reason' => $row->void_reason,
            'timeline' => $this->timeline('additional_cash', (int) $row->id),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function timeline(string $module, int $recordId): array
    {
        return AuditLog::query()
            ->with('user:id,full_name')
            ->where('module', $module)
            ->where('record_id', $recordId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(function (AuditLog $log) {
                $new = json_decode((string) $log->new_value, true);

                return [
                    'action' => $log->action,
                    'actor' => $log->user?->full_name ?? ($new['actor_name'] ?? 'System'),
                    'reason' => $log->reason,
                    'created_at' => HotelDateTime::utcIso($log->created_at),
                    'created_at_display' => HotelDateTime::formatUtcForDisplay($log->created_at),
                    'status' => is_array($new) ? ($new['status'] ?? null) : null,
                    'posted_shift_id' => is_array($new) ? ($new['posted_shift_id'] ?? $new['origin_shift_id'] ?? null) : null,
                ];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function expenseRow(Expense $expense): array
    {
        return [
            'id' => (int) $expense->id,
            'type' => 'Expense',
            'type_key' => 'expense',
            'reference' => $expense->reference,
            'created_at' => HotelDateTime::utcIso($expense->created_at),
            'created_at_display' => $expense->createdAtDisplay(),
            'origin_shift_id' => $expense->shift_session_id,
            'posted_shift_id' => $expense->posted_shift_session_id,
            'drawer' => $expense->cash_drawer,
            'amount' => (float) $expense->amount,
            'recorded_by_name' => $expense->user?->full_name,
            'reviewed_by_name' => $expense->reviewer?->full_name,
            'status' => $expense->status,
            'status_label' => $expense->displayStatus(),
            'reason' => $expense->notes,
            'category' => $expense->category?->name,
            'expense_date' => optional($expense->expense_date)?->format('Y-m-d'),
            'receipt_path' => $expense->receipt_path,
            'can_edit' => $expense->isPending(),
            'can_cancel' => $expense->isPending(),
            'can_pay' => $expense->isApproved(),
            'can_void' => $expense->allowsAccountingVoid(),
            'posted_shift_closed' => $expense->accountingShiftIsClosed(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function additionalCashRow(AdditionalCash $row): array
    {
        return [
            'id' => (int) $row->id,
            'type' => 'Additional Cash',
            'type_key' => 'additional_cash',
            'reference' => $row->reference,
            'created_at' => HotelDateTime::utcIso($row->created_at),
            'created_at_display' => $row->createdAtDisplay(),
            'origin_shift_id' => $row->shift_session_id,
            'posted_shift_id' => $row->shift_session_id,
            'drawer' => $row->cash_drawer,
            'amount' => (float) $row->amount,
            'recorded_by_name' => $row->user?->full_name,
            'reviewed_by_name' => null,
            'status' => $row->status,
            'status_label' => $row->displayStatus(),
            'reason' => $row->notes,
            'income_date' => optional($row->income_date)?->format('Y-m-d'),
            'receipt_path' => $row->receipt_path,
            'can_void' => $row->allowsAccountingVoid(),
            'posted_shift_closed' => $row->accountingShiftIsClosed(),
        ];
    }
}
