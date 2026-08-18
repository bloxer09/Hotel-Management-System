<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\StoreAdditionalCashRequest;
use App\Models\AdditionalCash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Shuchkin\SimpleXLSXGen;

class AdditionalCashController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!UserRole::allowsOperational($user->role)) {
            abort(403, 'Unauthorized access to additional cash.');
        }

        $sortBy = $request->input('sort_by', 'income_date');
        $sortDir = $request->input('sort_dir', 'desc');

        $allowedSorts = ['id', 'income_date', 'amount', 'cash_drawer', 'notes', 'recorded_by'];
        if (!in_array($sortBy, $allowedSorts)) $sortBy = 'income_date';
        if (!in_array($sortDir, ['asc', 'desc'])) $sortDir = 'desc';

        $query = AdditionalCash::with('user:id,full_name,username')
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

        return Inertia::render('AdditionalCash/Index', [
            'incomes' => $incomes,
            'filters' => $request->only(['from', 'to', 'search']),
            'summary' => $summary,
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
        ]);
    }

    public function store(StoreAdditionalCashRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();

        $receiptPath = null;
        if ($request->hasFile('receipt')) {
            $receiptPath = $request->file('receipt')->store('receipts', 'public');
        }

        AdditionalCash::create([
            'income_date' => $validated['income_date'],
            'amount' => $validated['amount'],
            'cash_drawer' => $validated['cash_drawer'],
            'notes' => $validated['notes'],
            'receipt_path' => $receiptPath,
            'recorded_by' => $user->id,
        ]);

        return back()->with('success', 'Additional cash recorded successfully.');
    }

    public function update(StoreAdditionalCashRequest $request, AdditionalCash $income)
    {
        $validated = $request->validated();

        $receiptPath = $income->receipt_path;
        if ($request->hasFile('receipt')) {
            if ($receiptPath) {
                Storage::disk('public')->delete($receiptPath);
            }
            $receiptPath = $request->file('receipt')->store('receipts', 'public');
        }

        $income->update([
            'income_date' => $validated['income_date'],
            'amount' => $validated['amount'],
            'cash_drawer' => $validated['cash_drawer'],
            'notes' => $validated['notes'],
            'receipt_path' => $receiptPath,
        ]);

        return back()->with('success', 'Additional cash updated successfully.');
    }

    public function destroy(Request $request, AdditionalCash $income)
    {
        $user = $request->user();
        if (!UserRole::allowsOperational($user->role)) {
            abort(403);
        }

        if ($income->receipt_path) {
            Storage::disk('public')->delete($income->receipt_path);
        }

        $income->delete();

        return back()->with('success', 'Additional cash deleted successfully.');
    }

    public function export(Request $request)
    {
        $user = $request->user();
        if (!UserRole::allowsOperational($user->role)) {
            abort(403);
        }

        $query = AdditionalCash::with('user:id,full_name')
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
        $rows[] = ['Hotel Management System — Additional Cash Report'];
        
        $from = $request->input('from', 'All Time');
        $to = $request->input('to', 'All Time');
        $rows[] = ['Period:', "{$from} to {$to}"];
        $rows[] = ['Generated:', date('Y-m-d H:i:s'), 'By:', $user->full_name];
        $rows[] = [];

        $rows[] = ['=== ADDITIONAL CASH INJECTION DETAILS ==='];
        $rows[] = ['ID', 'Date', 'Amount', 'Cash Drawer', 'Recorded By', 'Has Attachment', 'Notes'];

        $total = 0;
        foreach ($incomes as $inc) {
            $rows[] = [
                $inc->id,
                $inc->income_date->format('Y-m-d'),
                $inc->amount,
                ucfirst($inc->cash_drawer),
                $inc->user ? $inc->user->full_name : 'Unknown',
                $inc->receipt_path ? 'Yes' : 'No',
                $inc->notes
            ];
            $total += $inc->amount;
        }

        $rows[] = [];
        $rows[] = ['Total Additional Cash:', $total];

        $filename = "additional_cash_report_" . date('Y-m-d_H-i-s') . ".xlsx";
        SimpleXLSXGen::fromArray($rows)->downloadAs($filename);
    }
}
