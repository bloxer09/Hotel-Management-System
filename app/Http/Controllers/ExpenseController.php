<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'front_desk', 'cashier'], true)) {
            abort(403, 'Unauthorized access to expenses.');
        }

        $sortBy = $request->input('sort_by', 'expense_date');
        $sortDir = $request->input('sort_dir', 'desc');

        $allowedSorts = ['id', 'expense_date', 'amount', 'cash_drawer', 'notes', 'recorded_by'];
        if (!in_array($sortBy, $allowedSorts)) $sortBy = 'expense_date';
        if (!in_array($sortDir, ['asc', 'desc'])) $sortDir = 'desc';

        $query = \App\Models\Expense::with('user:id,full_name,username')
            ->orderBy($sortBy, $sortDir);
            
        if ($sortBy !== 'id') {
            $query->orderBy('id', 'desc');
        }

        if ($request->filled('from')) {
            $query->whereDate('expense_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('expense_date', '<=', $request->to);
        }
        if ($request->filled('search')) {
            $query->where('notes', 'like', '%' . $request->search . '%');
        }

        $expenses = $query->paginate(15)->withQueryString();

        $summary = [
            'total_amount' => $query->sum('amount'),
            'total_count' => $query->count()
        ];

        return \Inertia\Inertia::render('Expenses/Index', [
            'expenses' => $expenses,
            'filters' => $request->only(['from', 'to', 'search']),
            'summary' => $summary,
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'front_desk', 'cashier'], true)) {
            abort(403);
        }

        $validated = $request->validate([
            'expense_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'cash_drawer' => 'required|in:room,minibar',
            'notes' => 'nullable|string|max:1000',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        $receiptPath = null;
        if ($request->hasFile('receipt')) {
            $receiptPath = $request->file('receipt')->store('receipts', 'public');
        }

        \App\Models\Expense::create([
            'expense_date' => $validated['expense_date'],
            'amount' => $validated['amount'],
            'cash_drawer' => $validated['cash_drawer'],
            'notes' => $validated['notes'],
            'receipt_path' => $receiptPath,
            'recorded_by' => $user->id,
        ]);

        return back()->with('success', 'Expense recorded successfully.');
    }

    public function update(Request $request, \App\Models\Expense $expense)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'front_desk', 'cashier'], true)) {
            abort(403);
        }

        $validated = $request->validate([
            'expense_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'cash_drawer' => 'required|in:room,minibar',
            'notes' => 'nullable|string|max:1000',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        $receiptPath = $expense->receipt_path;
        if ($request->hasFile('receipt')) {
            if ($receiptPath) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($receiptPath);
            }
            $receiptPath = $request->file('receipt')->store('receipts', 'public');
        }

        $expense->update([
            'expense_date' => $validated['expense_date'],
            'amount' => $validated['amount'],
            'cash_drawer' => $validated['cash_drawer'],
            'notes' => $validated['notes'],
            'receipt_path' => $receiptPath,
        ]);

        return back()->with('success', 'Expense updated successfully.');
    }

    public function destroy(Request $request, \App\Models\Expense $expense)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'front_desk', 'cashier'], true)) {
            abort(403);
        }

        if ($expense->receipt_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($expense->receipt_path);
        }

        $expense->delete();

        return back()->with('success', 'Expense deleted successfully.');
    }

    public function export(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'front_desk', 'cashier'], true)) {
            abort(403);
        }

        $query = \App\Models\Expense::with('user:id,full_name')
            ->orderByDesc('expense_date')
            ->orderByDesc('id');

        if ($request->filled('from')) {
            $query->whereDate('expense_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('expense_date', '<=', $request->to);
        }
        if ($request->filled('search')) {
            $query->where('notes', 'like', '%' . $request->search . '%');
        }

        $expenses = $query->get();

        $rows = [];
        $rows[] = ['Hotel Management System — Expenses Report'];
        
        $from = $request->input('from', 'All Time');
        $to = $request->input('to', 'All Time');
        $rows[] = ['Period:', "{$from} to {$to}"];
        $rows[] = ['Generated:', date('Y-m-d H:i:s'), 'By:', $user->full_name];
        $rows[] = [];

        $rows[] = ['=== EXPENSE DETAILS ==='];
        $rows[] = ['ID', 'Date', 'Amount', 'Cash Drawer', 'Recorded By', 'Has Receipt', 'Notes'];

        $total = 0;
        foreach ($expenses as $exp) {
            $rows[] = [
                $exp->id,
                $exp->expense_date->format('Y-m-d'),
                $exp->amount,
                ucfirst($exp->cash_drawer),
                $exp->user ? $exp->user->full_name : 'Unknown',
                $exp->receipt_path ? 'Yes' : 'No',
                $exp->notes
            ];
            $total += $exp->amount;
        }

        $rows[] = [];
        $rows[] = ['Total Expenses:', $total];

        $filename = "expenses_report_" . date('Y-m-d_H-i-s') . ".xlsx";
        \Shuchkin\SimpleXLSXGen::fromArray($rows)->downloadAs($filename);
        exit;
    }
}
