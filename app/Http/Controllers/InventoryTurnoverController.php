<?php

namespace App\Http\Controllers;

use App\Exceptions\InventoryTurnoverException;
use App\Models\InventoryShiftTurnover;
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

        return back()->with('warning', 'Handover marked disputed. Outgoing expected/actual were not changed. Admin must confirm the accepted physical quantity.');
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
