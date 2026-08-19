<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\AdditionalCash;
use App\Models\Expense;
use App\Services\CashActivityHistoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CashActivityController extends Controller
{
    public function __construct(
        private readonly CashActivityHistoryService $history
    ) {}

    public function index(Request $request)
    {
        if ($request->user()->role !== UserRole::Admin->value) {
            abort(403, 'Unauthorized access.');
        }

        return Inertia::render('CashActivity/Index', [
            'activities' => $this->history->paginate($request->only(['type', 'status', 'search', 'page'])),
            'filters' => $request->only(['type', 'status', 'search']),
        ]);
    }

    public function showExpense(Request $request, Expense $expense)
    {
        if ($request->user()->role !== UserRole::Admin->value) {
            abort(403);
        }

        return Inertia::render('CashActivity/Show', [
            'activity' => $this->history->expenseDetail($expense),
        ]);
    }

    public function showAdditionalCash(Request $request, AdditionalCash $income)
    {
        if ($request->user()->role !== UserRole::Admin->value) {
            abort(403);
        }

        return Inertia::render('CashActivity/Show', [
            'activity' => $this->history->additionalCashDetail($income),
        ]);
    }
}
