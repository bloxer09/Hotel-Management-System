<?php

namespace App\Http\Controllers;

use App\Exceptions\InventoryTurnoverException;
use App\Models\InventoryShiftTurnover;
use App\Models\User;
use App\Services\InventoryTurnoverService;
use App\Services\ShiftService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class InventoryTurnoverController extends Controller
{
    public function __construct(private readonly InventoryTurnoverService $turnovers) {}

    public function show(Request $request)
    {
        $user = $request->user();
        $this->assertDeskRole($user);
        $shift = ShiftService::activeRegister();

        return Inertia::render('Shifts/InventoryTurnover', $this->turnovers->screenPayload($shift, $user));
    }

    public function history(Request $request)
    {
        $user = $request->user();
        $this->assertDeskRole($user);

        $filters = $request->only(['date', 'status', 'shift_session_id', 'employee_id']);

        return Inertia::render('Shifts/InventoryTurnoverHistory', [
            'turnovers' => $this->turnovers->history($user, $filters),
            'filters' => [
                'date' => $filters['date'] ?? '',
                'status' => $filters['status'] ?? '',
                'shift_session_id' => $filters['shift_session_id'] ?? '',
                'employee_id' => $filters['employee_id'] ?? '',
            ],
            'status_options' => InventoryShiftTurnover::STATUS_LABELS,
            'employees' => $user->role === 'admin'
                ? User::query()->whereIn('role', ['admin', 'front_desk'])->orderBy('full_name')->get(['id', 'full_name', 'role'])
                : [],
            'can_admin_resolve' => $user->role === 'admin',
            'disputed_count' => $this->turnovers->disputedCount(),
        ]);
    }

    public function showRecord(Request $request, InventoryShiftTurnover $turnover)
    {
        $user = $request->user();
        $this->assertDeskRole($user);
        $this->turnovers->assertCanView($user, $turnover);

        return Inertia::render('Shifts/InventoryTurnoverShow', [
            'turnover' => $this->turnovers->reportPayload($turnover),
            'can_admin_resolve' => $user->role === 'admin' && $turnover->status === InventoryShiftTurnover::STATUS_DISPUTED,
            'can_print' => true,
        ]);
    }

    public function print(Request $request, InventoryShiftTurnover $turnover)
    {
        $user = $request->user();
        $this->assertDeskRole($user);
        $this->turnovers->assertCanView($user, $turnover);
        $this->turnovers->recordReportExported($user, $turnover);

        return Inertia::render('Shifts/InventoryTurnoverPrint', $this->turnovers->printPayload($turnover));
    }

    public function acceptOpening(Request $request)
    {
        $this->assertDeskRole($request->user());
        $turnover = $this->currentTurnover();
        $this->turnovers->acceptOpening($request->user(), $turnover, $this->counts($request));

        return back()->with('success', 'Physical opening count saved. This is the tracked inventory baseline.');
    }

    public function startCounting(Request $request)
    {
        $this->assertDeskRole($request->user());
        $turnover = $this->currentTurnover();
        $this->turnovers->startCounting($request->user(), $turnover);

        return back()->with('success', 'Outgoing count started. Tracked inventory is locked until you submit or cancel.');
    }

    public function cancelCounting(Request $request)
    {
        $this->assertDeskRole($request->user());
        $turnover = $this->currentTurnover();
        $this->turnovers->cancelCounting($request->user(), $turnover);

        return back()->with('warning', 'Outgoing count cancelled. Tracked inventory posting is unlocked.');
    }

    public function submit(Request $request)
    {
        $this->assertDeskRole($request->user());
        $turnover = $this->currentTurnover();
        $this->turnovers->submit(
            $request->user(),
            $turnover,
            $this->counts($request),
            $request->input('notes')
        );

        return back()->with('success', 'Inventory turnover submitted. Incoming staff must verify the physical handover.');
    }

    public function acceptHandover(Request $request)
    {
        $this->assertDeskRole($request->user());
        $turnover = $this->pendingTurnover();
        $this->turnovers->acceptHandover(
            $request->user(),
            $turnover,
            $this->counts($request),
            $request->input('notes')
        );

        return back()->with('success', 'Handover accepted. Incoming opening stock is the verified physical count.');
    }

    public function disputeHandover(Request $request)
    {
        $this->assertDeskRole($request->user());
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);
        $turnover = $this->pendingTurnover();
        $this->turnovers->disputeHandover(
            $request->user(),
            $turnover,
            $this->counts($request),
            (string) $request->input('reason')
        );

        return back()->with('warning', 'Handover marked disputed. Live stock and outgoing expected/actual were not changed. Admin must confirm the accepted physical quantity.');
    }

    public function requestRecount(Request $request, InventoryShiftTurnover $turnover)
    {
        if ($request->user()->role !== 'admin') {
            abort(403, 'Only administrators can request a handover recount.');
        }

        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $this->turnovers->requestRecount(
            $request->user(),
            $turnover,
            (string) $request->input('reason')
        );

        return back()->with('warning', 'Recount requested. Status remains disputed. Inventory was not changed.');
    }

    public function resolveDispute(Request $request, InventoryShiftTurnover $turnover)
    {
        if ($request->user()->role !== 'admin') {
            abort(403, 'Only administrators can resolve inventory handover disputes.');
        }

        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $this->turnovers->resolveDispute(
            $request->user(),
            $turnover,
            $this->counts($request),
            (string) $request->input('reason')
        );

        return back()->with('success', 'Handover dispute resolved. Outgoing snapshot was not rewritten.');
    }

    public function destroy(Request $request, InventoryShiftTurnover $turnover)
    {
        $this->assertDeskRole($request->user());
        abort(403, 'Submitted and accepted inventory turnovers cannot be deleted. Use an audited resolution instead.');
    }

    private function currentTurnover(): InventoryShiftTurnover
    {
        $shift = ShiftService::activeRegister();
        if (! $shift) {
            throw new InventoryTurnoverException('An active Front Desk register is required for this inventory turnover action.');
        }

        $turnover = $this->turnovers->ensureForShift($shift);
        if (! $turnover) {
            throw new InventoryTurnoverException('No products are configured for inventory turnover. Admin must mark notebook items as turnover tracked.');
        }

        return $turnover;
    }

    private function pendingTurnover(): InventoryShiftTurnover
    {
        $pending = $this->turnovers->pendingHandover();
        if (! $pending) {
            throw new InventoryTurnoverException('There is no submitted inventory handover waiting for verification.');
        }

        return $pending;
    }

    private function counts(Request $request): array
    {
        $request->validate([
            'counts' => 'required|array',
            'counts.*.inventory_item_id' => 'required|integer',
            'counts.*.quantity' => 'nullable',
            'notes' => 'nullable|string|max:500',
        ]);

        return $request->input('counts', []);
    }

    private function assertDeskRole($user): void
    {
        if ($user->role === 'housekeeping') {
            abort(403, 'Housekeeping cannot perform inventory turnover actions.');
        }

        if (! in_array($user->role, ['admin', 'front_desk'], true)) {
            abort(403, 'Unauthorized inventory turnover action.');
        }
    }
}
