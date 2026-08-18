<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\StoreExpenseRequest;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Shuchkin\SimpleXLSXGen;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (! UserRole::allowsOperational($user->role)) {
            abort(403, 'Unauthorized access to expenses.');
        }

        ExpenseCategory::ensureDefaults();

        $sortBy = $request->input('sort_by', 'expense_date');
        $sortDir = $request->input('sort_dir', 'desc');

        $allowedSorts = ['id', 'expense_date', 'amount', 'cash_drawer', 'notes', 'recorded_by', 'category'];
        if (! in_array($sortBy, $allowedSorts)) {
            $sortBy = 'expense_date';
        }
        if (! in_array($sortDir, ['asc', 'desc'])) {
            $sortDir = 'desc';
        }

        $filtered = $this->filteredExpensesQuery($request);

        $listQuery = (clone $filtered)->with(['user:id,full_name,username', 'category:id,name']);

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

        $summary = [
            'total_amount' => (clone $filtered)->sum('amount'),
            'total_count' => (clone $filtered)->count(),
        ];

        return Inertia::render('Expenses/Index', [
            'expenses' => $expenses,
            'categories' => ExpenseCategory::query()->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['from', 'to', 'search', 'category']),
            'summary' => $summary,
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
        ]);
    }

    public function store(StoreExpenseRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();
        $category = ExpenseCategory::findOrCreateFromName($validated['category']);

        $receiptPath = null;
        if ($request->hasFile('receipt')) {
            $receiptPath = $request->file('receipt')->store('receipts', 'public');
        }

        Expense::create([
            'expense_date' => $validated['expense_date'],
            'amount' => $validated['amount'],
            'cash_drawer' => $validated['cash_drawer'],
            'notes' => $validated['notes'],
            'expense_category_id' => $category->id,
            'receipt_path' => $receiptPath,
            'recorded_by' => $user->id,
        ]);

        return back()->with('success', 'Expense recorded successfully.');
    }

    public function update(StoreExpenseRequest $request, Expense $expense)
    {
        $validated = $request->validated();
        $category = ExpenseCategory::findOrCreateFromName($validated['category']);

        $receiptPath = $expense->receipt_path;
        if ($request->hasFile('receipt')) {
            if ($receiptPath) {
                Storage::disk('public')->delete($receiptPath);
            }
            $receiptPath = $request->file('receipt')->store('receipts', 'public');
        }

        $expense->update([
            'expense_date' => $validated['expense_date'],
            'amount' => $validated['amount'],
            'cash_drawer' => $validated['cash_drawer'],
            'notes' => $validated['notes'],
            'expense_category_id' => $category->id,
            'receipt_path' => $receiptPath,
        ]);

        return back()->with('success', 'Expense updated successfully.');
    }

    public function destroy(Request $request, Expense $expense)
    {
        $user = $request->user();
        if (! UserRole::allowsOperational($user->role)) {
            abort(403);
        }

        if ($expense->receipt_path) {
            Storage::disk('public')->delete($expense->receipt_path);
        }

        $expense->delete();

        return back()->with('success', 'Expense deleted successfully.');
    }

    public function export(Request $request)
    {
        $user = $request->user();
        if (! UserRole::allowsOperational($user->role)) {
            abort(403);
        }

        $expenses = $this->filteredExpensesQuery($request)
            ->with(['user:id,full_name', 'category:id,name'])
            ->orderByDesc('expense_date')
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
        $rows[] = ['ID', 'Date', 'Amount', 'Cash Drawer', 'Recorded By', 'Has Receipt', 'Notes', 'Category'];

        $total = 0;
        foreach ($expenses as $exp) {
            $rows[] = [
                $exp->id,
                $exp->expense_date->format('Y-m-d'),
                $exp->amount,
                ucfirst($exp->cash_drawer),
                $exp->user ? $exp->user->full_name : 'Unknown',
                $exp->receipt_path ? 'Yes' : 'No',
                $exp->notes,
                $exp->category?->name ?? ExpenseCategory::UNCATEGORIZED,
            ];
            $total += $exp->amount;
        }

        $rows[] = [];
        $rows[] = ['Total Expenses:', $total];

        return $rows;
    }

    private function filteredExpensesQuery(Request $request)
    {
        $query = Expense::query();

        if ($request->filled('from')) {
            $query->whereDate('expense_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('expense_date', '<=', $request->to);
        }
        if ($request->filled('category')) {
            $query->where('expense_category_id', $request->integer('category'));
        }
        if ($request->filled('search')) {
            $term = '%'.$request->search.'%';
            $query->where(function ($inner) use ($term) {
                $inner->where('notes', 'like', $term)
                    ->orWhereHas('category', function ($categoryQuery) use ($term) {
                        $categoryQuery->where('name', 'like', $term);
                    });
            });
        }

        return $query;
    }
}
