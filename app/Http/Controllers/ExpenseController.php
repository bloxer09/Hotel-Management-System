<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\AnnotateClosedCashActivityRequest;
use App\Http\Requests\RejectExpenseRequest;
use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\VoidCashActivityRequest;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ShiftSession;
use App\Services\CashActivityHistoryService;
use App\Services\ExpenseLedgerService;
use App\Services\ShiftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use InvalidArgumentException;
use Shuchkin\SimpleXLSXGen;

class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseLedgerService $ledger,
        private readonly CashActivityHistoryService $history
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        if (! UserRole::allowsOperational($user->role)) {
            abort(403, 'Unauthorized access to expenses.');
        }

        ExpenseCategory::ensureDefaults();

        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');

        $allowedSorts = ['id', 'reference', 'expense_date', 'created_at', 'amount', 'cash_drawer', 'notes', 'recorded_by', 'category', 'status'];
        if (! in_array($sortBy, $allowedSorts)) {
            $sortBy = 'created_at';
        }
        if (! in_array($sortDir, ['asc', 'desc'])) {
            $sortDir = 'desc';
        }

        $filtered = $this->filteredExpensesQuery($request);

        $listQuery = (clone $filtered)->with([
            'user:id,full_name,username',
            'category:id,name',
            'originShift:id,shift_code,user_id,ended_at',
            'postedShift:id,shift_code,user_id,ended_at',
            'reviewer:id,full_name',
            'poster:id,full_name',
        ]);

        if ($sortBy === 'category') {
            $listQuery->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
                ->orderBy('expense_categories.name', $sortDir)
                ->orderBy('expenses.id', 'desc')
                ->select('expenses.*');
        } else {
            $listQuery->orderBy($sortBy, $sortDir);
            if ($sortBy !== 'id') {
                $listQuery->orderBy('id', 'desc');
            }
        }

        $expenses = $listQuery->paginate(15)->withQueryString();
        $expenses->getCollection()->transform(fn (Expense $row) => $this->serializeExpense($row, $user));

        $summary = [
            'total_amount' => (clone $filtered)->where('status', Expense::STATUS_POSTED)->sum('amount'),
            'total_count' => (clone $filtered)->count(),
            'pending_count' => (clone $filtered)->where('status', Expense::STATUS_PENDING_APPROVAL)->count(),
            'approved_unpaid_count' => (clone $filtered)->where('status', Expense::STATUS_APPROVED)->count(),
        ];

        $register = ShiftService::activeRegister();

        return Inertia::render('Expenses/Index', [
            'expenses' => $expenses,
            'categories' => ExpenseCategory::query()->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['from', 'to', 'search', 'category', 'status']),
            'summary' => $summary,
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
            'approval_threshold' => Expense::APPROVAL_THRESHOLD,
            'can_operate_register' => $user->role === UserRole::Admin->value
                || ($register && (int) $register->user_id === (int) $user->id),
            'is_admin' => $user->role === UserRole::Admin->value,
        ]);
    }

    public function store(StoreExpenseRequest $request)
    {
        try {
            $receiptPath = $request->hasFile('receipt')
                ? $request->file('receipt')->store('receipts', 'public')
                : null;
            $expense = $this->ledger->submit($request->user(), $request->validated(), $receiptPath);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        $message = $expense->isPosted()
            ? "Expense {$expense->reference} recorded and posted to the active drawer."
            : "Expense {$expense->reference} submitted for Admin approval. Drawer cash will not change until it is approved and marked paid.";

        return back()->with('success', $message);
    }

    public function update(StoreExpenseRequest $request, Expense $expense)
    {
        $receiptPath = null;
        if ($request->hasFile('receipt')) {
            if ($expense->receipt_path) {
                Storage::disk('public')->delete($expense->receipt_path);
            }
            $receiptPath = $request->file('receipt')->store('receipts', 'public');
        }

        try {
            $this->ledger->updatePending($expense, $request->user(), $request->validated(), $receiptPath);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        return back()->with('success', 'Pending expense updated.');
    }

    public function destroy(Request $request, Expense $expense)
    {
        $reason = trim((string) $request->input('reason', 'Cancelled by submitter.'));
        try {
            $this->ledger->cancelPending($expense, $request->user(), $reason !== '' ? $reason : 'Cancelled by submitter.');
        } catch (InvalidArgumentException $e) {
            abort(403, $e->getMessage());
        }

        return back()->with('success', "Expense {$expense->reference} cancelled.");
    }

    public function approvals(Request $request)
    {
        if ($request->user()->role !== UserRole::Admin->value) {
            abort(403);
        }

        $filter = (string) $request->query('filter', 'pending');
        if (! in_array($filter, ['pending', 'approved', 'all'], true)) {
            $filter = 'pending';
        }

        $query = Expense::query()
            ->with(['user:id,full_name', 'category:id,name', 'originShift:id,shift_code', 'reviewer:id,full_name'])
            ->where('amount', '>', Expense::APPROVAL_THRESHOLD)
            ->orderByDesc('created_at');

        match ($filter) {
            'pending' => $query->where('status', Expense::STATUS_PENDING_APPROVAL),
            'approved' => $query->where('status', Expense::STATUS_APPROVED),
            default => $query->whereIn('status', [
                Expense::STATUS_PENDING_APPROVAL,
                Expense::STATUS_APPROVED,
                Expense::STATUS_POSTED,
                Expense::STATUS_REJECTED,
            ]),
        };

        $rows = $query->limit(200)->get()->map(fn (Expense $row) => $this->serializeExpense($row, $request->user()));

        return Inertia::render('Expenses/Approvals', [
            'filter' => $filter,
            'rows' => $rows,
        ]);
    }

    public function show(Request $request, Expense $expense)
    {
        if ($request->user()->role !== UserRole::Admin->value) {
            abort(403);
        }

        return Inertia::render('Expenses/Review', [
            'expense' => $this->history->expenseDetail($expense),
        ]);
    }

    public function approve(Request $request, Expense $expense)
    {
        try {
            $this->ledger->approve($expense, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Expense {$expense->reference} approved. Front Desk must still mark it paid before drawer cash changes.");
    }

    public function reject(RejectExpenseRequest $request, Expense $expense)
    {
        try {
            $this->ledger->reject($expense, $request->user(), (string) $request->input('review_notes'));
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Expense {$expense->reference} rejected.");
    }

    public function markPaid(Request $request, Expense $expense)
    {
        try {
            $this->ledger->markPaid($expense, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Expense {$expense->reference} marked paid. Expected drawer cash has been reduced.");
    }

    public function void(VoidCashActivityRequest $request, Expense $expense)
    {
        try {
            $this->ledger->voidPosted(
                $expense,
                $request->user(),
                (string) $request->input('reason'),
                $request->boolean('confirm_no_physical_movement')
            );
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Expense {$expense->reference} voided as an erroneous posting. Live expected cash no longer includes this amount because the physical cash movement did not occur.");
    }

    public function annotateClosed(AnnotateClosedCashActivityRequest $request, Expense $expense)
    {
        try {
            $this->ledger->annotateClosedPosted($expense, $request->user(), (string) $request->input('reason'));
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Audit note added to {$expense->reference}. Posted financial treatment is unchanged.");
    }

    public function export(Request $request)
    {
        $user = $request->user();
        if (! UserRole::allowsOperational($user->role)) {
            abort(403);
        }

        $expenses = $this->filteredExpensesQuery($request)
            ->with(['user:id,full_name', 'category:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $rows = $this->buildExportRows($expenses, $user, $request);

        $filename = 'expenses_report_'.date('Y-m-d_H-i-s').'.xlsx';
        SimpleXLSXGen::fromArray($rows)->downloadAs($filename);
        exit;
    }

    public function buildExportRows($expenses, $user, Request $request): array
    {
        $rows = [];
        $rows[] = ['Hotel Management System — Expenses Report'];

        $from = $request->input('from', 'All Time');
        $to = $request->input('to', 'All Time');
        $rows[] = ['Period:', "{$from} to {$to}"];

        if ($request->filled('category')) {
            $categoryName = ExpenseCategory::query()->find($request->category)?->name
                ?? $request->input('category');
            $rows[] = ['Category:', $categoryName];
        }

        $rows[] = ['Generated:', date('Y-m-d H:i:s'), 'By:', $user->full_name];
        $rows[] = [];

        $rows[] = ['=== EXPENSE DETAILS ==='];
        $rows[] = ['ID', 'Reference', 'Date', 'Recorded At', 'Amount', 'Cash Drawer', 'Status', 'Recorded By', 'Has Receipt', 'Notes', 'Category'];

        $total = 0;
        foreach ($expenses as $exp) {
            $rows[] = [
                $exp->id,
                $exp->reference,
                $exp->expense_date->format('Y-m-d'),
                $exp->createdAtDisplay(),
                $exp->amount,
                ucfirst($exp->cash_drawer),
                $exp->displayStatus(),
                $exp->user ? $exp->user->full_name : 'Unknown',
                $exp->receipt_path ? 'Yes' : 'No',
                $exp->notes,
                $exp->category?->name ?? ExpenseCategory::UNCATEGORIZED,
            ];
            if ($exp->status === Expense::STATUS_POSTED) {
                $total += $exp->amount;
            }
        }

        $rows[] = [];
        $rows[] = ['Total Posted Expenses:', $total];

        return $rows;
    }

    private function filteredExpensesQuery(Request $request)
    {
        $user = $request->user();
        $query = Expense::query();

        if ($user->role !== UserRole::Admin->value) {
            $shiftIds = ShiftSession::query()->where('user_id', $user->id)->pluck('id');
            $query->where(function ($inner) use ($user, $shiftIds) {
                $inner->where('recorded_by', $user->id)
                    ->orWhere('status', Expense::STATUS_APPROVED)
                    ->orWhereIn('shift_session_id', $shiftIds)
                    ->orWhereIn('posted_shift_session_id', $shiftIds);
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('expense_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('expense_date', '<=', $request->to);
        }
        if ($request->filled('category')) {
            $query->where('expense_category_id', $request->integer('category'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $term = '%'.$request->search.'%';
            $query->where(function ($inner) use ($term) {
                $inner->where('notes', 'like', $term)
                    ->orWhere('reference', 'like', $term)
                    ->orWhereHas('category', function ($categoryQuery) use ($term) {
                        $categoryQuery->where('name', 'like', $term);
                    });
            });
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeExpense(Expense $expense, $user): array
    {
        $row = $this->history->expenseRow($expense);
        $row['user'] = $expense->user ? ['id' => $expense->user->id, 'full_name' => $expense->user->full_name] : null;
        $row['category'] = $expense->category ? ['id' => $expense->category->id, 'name' => $expense->category->name] : null;
        $row['notes'] = $expense->notes;
        $row['cash_drawer'] = $expense->cash_drawer;
        $row['review_notes'] = $expense->review_notes;
        $row['reviewed_at_display'] = $expense->reviewedAtDisplay();
        $row['posted_at_display'] = $expense->postedAtDisplay();
        $row['reviewer_name'] = $expense->reviewer?->full_name;
        $row['poster_name'] = $expense->poster?->full_name;
        $row['can_edit'] = $expense->isPending() && (
            $user->role === UserRole::Admin->value || (int) $expense->recorded_by === (int) $user->id
        );
        $row['can_cancel'] = $row['can_edit'];
        $row['can_pay'] = $expense->isApproved();
        $row['can_void'] = $expense->allowsAccountingVoid() && $user->role === UserRole::Admin->value;
        $row['posted_shift_closed'] = $expense->accountingShiftIsClosed();
        $row['timeline'] = $user->role === UserRole::Admin->value ? $this->history->timeline('expenses', (int) $expense->id) : [];

        return $row;
    }
}
