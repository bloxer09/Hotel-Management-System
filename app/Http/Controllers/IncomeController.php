<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class IncomeController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'front_desk', 'cashier'], true)) {
            abort(403, 'Unauthorized access to additional incomes.');
        }

        $sortBy = $request->input('sort_by', 'income_date');
        $sortDir = $request->input('sort_dir', 'desc');

        $allowedSorts = ['id', 'income_date', 'amount', 'notes', 'recorded_by'];
        if (!in_array($sortBy, $allowedSorts)) $sortBy = 'income_date';
        if (!in_array($sortDir, ['asc', 'desc'])) $sortDir = 'desc';

        $query = \App\Models\Income::with('user:id,full_name,username')
            ->orderBy($sortBy, $sortDir);
            
        if ($sortBy !== 'id') {
            $query->orderBy('id', 'desc');
        }

        if ($request->filled('from')) {
            $query->whereDate('income_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('income_date', '<=', $request->to);
        }
        if ($request->filled('search')) {
            $query->where('notes', 'like', '%' . $request->search . '%');
        }

        $incomes = $query->paginate(15)->withQueryString();

        $summary = [
            'total_amount' => $query->sum('amount'),
            'total_count' => $query->count()
        ];

        return \Inertia\Inertia::render('Incomes/Index', [
            'incomes' => $incomes,
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
            'income_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:1000',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        $receiptPath = null;
        if ($request->hasFile('receipt')) {
            $receiptPath = $request->file('receipt')->store('receipts', 'public');
        }

        \App\Models\Income::create([
            'income_date' => $validated['income_date'],
            'amount' => $validated['amount'],
            'notes' => $validated['notes'],
            'receipt_path' => $receiptPath,
            'recorded_by' => $user->id,
        ]);

        return back()->with('success', 'Additional income recorded successfully.');
    }

    public function update(Request $request, \App\Models\Income $income)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'front_desk', 'cashier'], true)) {
            abort(403);
        }

        $validated = $request->validate([
            'income_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:1000',
            'receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        $receiptPath = $income->receipt_path;
        if ($request->hasFile('receipt')) {
            if ($receiptPath) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($receiptPath);
            }
            $receiptPath = $request->file('receipt')->store('receipts', 'public');
        }

        $income->update([
            'income_date' => $validated['income_date'],
            'amount' => $validated['amount'],
            'notes' => $validated['notes'],
            'receipt_path' => $receiptPath,
        ]);

        return back()->with('success', 'Additional income updated successfully.');
    }

    public function destroy(Request $request, \App\Models\Income $income)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'front_desk', 'cashier'], true)) {
            abort(403);
        }

        if ($income->receipt_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($income->receipt_path);
        }

        $income->delete();

        return back()->with('success', 'Additional income deleted successfully.');
    }

    public function export(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'front_desk', 'cashier'], true)) {
            abort(403);
        }

        $query = \App\Models\Income::with('user:id,full_name')
            ->orderByDesc('income_date')
            ->orderByDesc('id');

        if ($request->filled('from')) {
            $query->whereDate('income_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('income_date', '<=', $request->to);
        }
        if ($request->filled('search')) {
            $query->where('notes', 'like', '%' . $request->search . '%');
        }

        $incomes = $query->get();

        $rows = [];
        $rows[] = ['Hotel Management System — Additional Incomes Report'];
        
        $from = $request->input('from', 'All Time');
        $to = $request->input('to', 'All Time');
        $rows[] = ['Period:', "{$from} to {$to}"];
        $rows[] = ['Generated:', date('Y-m-d H:i:s'), 'By:', $user->full_name];
        $rows[] = [];

        $rows[] = ['=== INCOME INJECTION DETAILS ==='];
        $rows[] = ['ID', 'Date', 'Amount', 'Recorded By', 'Has Attachment', 'Notes'];

        $total = 0;
        foreach ($incomes as $inc) {
            $rows[] = [
                $inc->id,
                $inc->income_date->format('Y-m-d'),
                $inc->amount,
                $inc->user ? $inc->user->full_name : 'Unknown',
                $inc->receipt_path ? 'Yes' : 'No',
                $inc->notes
            ];
            $total += $inc->amount;
        }

        $rows[] = [];
        $rows[] = ['Total Additional Income:', $total];

        $filename = "incomes_report_" . date('Y-m-d_H-i-s') . ".xlsx";
        \Shuchkin\SimpleXLSXGen::fromArray($rows)->downloadAs($filename);
        exit;
    }
}
