<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceTicket;
use App\Models\Room;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Services\BookingService;

class MaintenanceController extends Controller
{
    public function index(Request $request)
    {
        $tickets = MaintenanceTicket::with(['room.roomType', 'reportedBy', 'resolvedBy'])
            ->orderBy('id', 'desc')
            ->get();

        $rooms = Room::orderBy('room_number', 'asc')->get();

        return Inertia::render('Maintenance/Index', [
            'tickets' => $tickets,
            'rooms' => $rooms,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'title' => 'required|string|max:100',
            'description' => 'nullable|string',
            'priority' => 'required|in:low,medium,high,critical',
        ]);

        $validated['reported_by'] = $request->user()->id;
        $validated['status'] = 'open';

        $ticket = MaintenanceTicket::create($validated);

        // Optional: Log in Audit Logs
        BookingService::auditLog(
            $request->user()->id,
            'MAINTENANCE_TICKET_CREATED',
            'MAINTENANCE',
            $ticket->id,
            null,
            $ticket
        );

        return redirect()->back()->with('success', 'Maintenance ticket submitted successfully.');
    }

    public function update(Request $request, MaintenanceTicket $ticket)
    {
        $user = $request->user();
        
        $validated = $request->validate([
            'status' => 'nullable|in:open,in_progress,closed',
            'notes' => 'nullable|string',
            'priority' => 'nullable|in:low,medium,high,critical',
        ]);

        // Authorization checks for closing tickets
        if (isset($validated['status']) && $validated['status'] === 'closed') {
            if ($user->role !== 'admin' && $user->role !== 'front_desk') {
                abort(403, 'Only Admin and Front Desk staff can close maintenance tickets.');
            }
            $validated['resolved_by'] = $user->id;
            $validated['resolved_at'] = now();

            // Auto-transition room to cleaning if currently out of order
            $room = $ticket->room;
            if ($room && $room->status === 'out_of_order') {
                $room->status = 'cleaning';
                $room->assigned_housekeeper = null;
                $room->cleaning_started_at = now();
                $room->save();
            }
        } elseif (isset($validated['status'])) {
            // Reopening or moving to in progress
            $validated['resolved_by'] = null;
            $validated['resolved_at'] = null;
        }

        $oldVal = $ticket->toArray();
        $ticket->update($validated);

        BookingService::auditLog(
            $user->id,
            'MAINTENANCE_TICKET_UPDATED',
            'MAINTENANCE',
            $ticket->id,
            $oldVal,
            $ticket
        );

        return redirect()->back()->with('success', 'Maintenance ticket updated successfully.');
    }
}
