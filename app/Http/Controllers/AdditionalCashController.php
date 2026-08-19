<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\AnnotateClosedCashActivityRequest;
use App\Http\Requests\StoreAdditionalCashRequest;
use App\Http\Requests\VoidCashActivityRequest;
use App\Models\AdditionalCash;
use App\Models\ShiftSession;
use App\Services\AdditionalCashLedgerService;
use App\Services\CashActivityHistoryService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;
use Shuchkin\SimpleXLSXGen;

class AdditionalCashController extends Controller
{
    public function __construct(
        private readonly AdditionalCashLedgerService $ledger,
        private readonly CashActivityHistoryService $history
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        if (! UserRole::allowsOperational($user->role)) {
            abort(403, 'Unauthorized access to additional cash.');
        }

        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');

        $allowedSorts = ['id', 'reference', 'income_date', 'created_at', 'amount', 'cash_drawer', 'notes', 'recorded_by', 'status'];
        if (! in_array($sortBy, $allowedSorts)) {
            $sortBy = 'created_at';
        }
        if (! in_array($sortDir, ['asc', 'desc'])) {
            $sortDir = 'desc';
        }

        $query = AdditionalCash::with(['user:id,full_name,username', 'originShift:id,shift_code,ended_at'])
            ->orderBy($sortBy, $sortDir);

        if ($sortBy !== 'id') {
            $query->orderBy('id', 'desc');
        }

        if ($user->role !== UserRole::Admin->value) {
            $shiftIds = ShiftSession::query()->where('user_id', $user->id)->pluck('id');
            $query->where(function ($inner) use ($user, $shiftIds) {
                $inner->where('recorded_by', $user->id)
                    ->orWhereIn('shift_session_id', $shiftIds);
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('income_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('income_date', '<=', $request->to);
        }
        if ($request->filled('search')) {
            $term = '%'.$request->search.'%';
            $query->where(function ($inner) use ($term) {
                $inner->where('notes', 'like', $term)
                    ->orWhere('reference', 'like', $term);
            });
        }

        $incomes = $query->paginate(15)->withQueryString();
        $incomes->getCollection()->transform(function (AdditionalCash $row) use ($user) {
            $payload = $this->history->additionalCashRow($row);
            $payload['user'] = $row->user ? ['id' => $row->user->id, 'full_name' => $row->user->full_name] : null;
            $payload['notes'] = $row->notes;
            $payload['cash_drawer'] = $row->cash_drawer;
            $payload['can_void'] = $row->allowsAccountingVoid() && $user->role === UserRole::Admin->value;
            $payload['posted_shift_closed'] = $row->accountingShiftIsClosed();

            return $payload;
        });

        $base = AdditionalCash::query();
        if ($user->role !== UserRole::Admin->value) {
            $shiftIds = ShiftSession::query()->where('user_id', $user->id)->pluck('id');
            $base->where(function ($inner) use ($user, $shiftIds) {
                $inner->where('recorded_by', $user->id)
                    ->orWhereIn('shift_session_id', $shiftIds);
            });
        }

        $summary = [
            'total_amount' => (clone $base)->where('status', AdditionalCash::STATUS_POSTED)->sum('amount'),
            'total_count' => (clone $base)->count(),
        ];

        return Inertia::render('AdditionalCash/Index', [
            'incomes' => $incomes,
            'filters' => $request->only(['from', 'to', 'search']),
            'summary' => $summary,
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
            'is_admin' => $user->role === UserRole::Admin->value,
        ]);
    }

    public function store(StoreAdditionalCashRequest $request)
    {
        try {
            $receiptPath = $request->hasFile('receipt')
                ? $request->file('receipt')->store('receipts', 'public')
                : null;
            $row = $this->ledger->record($request->user(), $request->validated(), $receiptPath);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return back()->with('success', "Additional cash {$row->reference} recorded. Expected drawer cash increased.");
    }

    public function update()
    {
        abort(403, 'Posted additional cash cannot be edited. Void it and record a correction if needed.');
    }

    public function destroy()
    {
        abort(403, 'Posted additional cash cannot be deleted. Use Void with a reason.');
    }

    public function void(VoidCashActivityRequest $request, AdditionalCash $income)
    {
        try {
            $this->ledger->voidPosted(
                $income,
                $request->user(),
                (string) $request->input('reason'),
                $request->boolean('confirm_no_physical_movement')
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Additional cash {$income->reference} voided as an erroneous posting. Live expected cash no longer includes this amount because the physical cash movement did not occur.");
    }

    public function annotateClosed(AnnotateClosedCashActivityRequest $request, AdditionalCash $income)
    {
        try {
            $this->ledger->annotateClosedPosted($income, $request->user(), (string) $request->input('reason'));
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Audit note added to {$income->reference}. Posted financial treatment is unchanged.");
    }

    public function export(Request $request)
    {
        $user = $request->user();
        if (! UserRole::allowsOperational($user->role)) {
            abort(403);
        }

        $query = AdditionalCash::with('user:id,full_name')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($user->role !== UserRole::Admin->value) {
            $shiftIds = ShiftSession::query()->where('user_id', $user->id)->pluck('id');
            $query->where(function ($inner) use ($user, $shiftIds) {
                $inner->where('recorded_by', $user->id)
                    ->orWhereIn('shift_session_id', $shiftIds);
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('income_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('income_date', '<=', $request->to);
        }
        if ($request->filled('search')) {
            $query->where(function ($inner) use ($request) {
                $term = '%'.$request->search.'%';
                $inner->where('notes', 'like', $term)
                    ->orWhere('reference', 'like', $term);
            });
        }

        $incomes = $query->get();

        $rows = [];
        $rows[] = ['Hotel Management System — Additional Cash Report'];

        $from = $request->input('from', 'All Time');
        $to = $request->input('to', 'All Time');
        $rows[] = ['Period:', "{$from} to {$to}"];
        $rows[] = ['Generated:', date('Y-m-d H:i:s'), 'By:', $user->full_name];
        $rows[] = [];

        $rows[] = ['=== ADDITIONAL CASH INJECTION DETAILS ==='];
        $rows[] = ['ID', 'Reference', 'Date', 'Recorded At', 'Amount', 'Cash Drawer', 'Status', 'Recorded By', 'Has Attachment', 'Source / Reason'];

        $total = 0;
        foreach ($incomes as $inc) {
            $rows[] = [
                $inc->id,
                $inc->reference,
                $inc->income_date->format('Y-m-d'),
                $inc->createdAtDisplay(),
                $inc->amount,
                ucfirst($inc->cash_drawer),
                $inc->displayStatus(),
                $inc->user ? $inc->user->full_name : 'Unknown',
                $inc->receipt_path ? 'Yes' : 'No',
                $inc->notes,
            ];
            if ($inc->status === AdditionalCash::STATUS_POSTED) {
                $total += $inc->amount;
            }
        }

        $rows[] = [];
        $rows[] = ['Total Posted Additional Cash:', $total];

        $filename = 'additional_cash_report_'.date('Y-m-d_H-i-s').'.xlsx';
        SimpleXLSXGen::fromArray($rows)->downloadAs($filename);
    }
}
