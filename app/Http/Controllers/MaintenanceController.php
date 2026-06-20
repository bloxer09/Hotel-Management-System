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
        $sortBy = $request->input('sort_by', 'id');
        $sortDir = $request->input('sort_dir', 'desc');
        $search = $request->input('search');
        $status = $request->input('status', 'all');

        $allowedSorts = ['id', 'room_id', 'title', 'status', 'priority', 'created_at'];
        if (!in_array($sortBy, $allowedSorts)) $sortBy = 'id';
        if (!in_array($sortDir, ['asc', 'desc'])) $sortDir = 'desc';

        $query = MaintenanceTicket::with(['room.type', 'reportedBy', 'resolvedBy']);

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhereHas('room', function($r) use ($search) {
                      $r->where('room_number', 'like', "%{$search}%");
                  });
            });
        }

        $tickets = $query->orderBy($sortBy, $sortDir)
            ->paginate(15)
            ->withQueryString();

        $rooms = Room::orderBy('room_number', 'asc')->get();

        return Inertia::render('Maintenance/Index', [
            'tickets' => $tickets,
            'rooms' => $rooms,
            'filters' => ['search' => $search, 'status' => $status],
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'title' => 'required|string|max:100',
            'description' => 'nullable|string',
            'priority' => 'required|in:low,medium,high,critical',
            'attachment' => 'nullable|file|max:10240', // Max 10MB
        ]);

        $validated['reported_by'] = $request->user()->id;
        $validated['status'] = 'open';

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $path = $file->store('maintenance', 'public');
            $validated['attachment_path'] = '/storage/' . $path;
        }

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
            'room_id' => 'nullable|exists:rooms,id',
            'title' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'attachment' => 'nullable|file|max:10240', // Max 10MB
            'remove_attachment' => 'nullable|boolean',
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

        if ($request->hasFile('attachment')) {
            if ($ticket->attachment_path) {
                $oldFile = str_replace('/storage/', '', $ticket->attachment_path);
                \Storage::disk('public')->delete($oldFile);
            }
            $file = $request->file('attachment');
            $path = $file->store('maintenance', 'public');
            $validated['attachment_path'] = '/storage/' . $path;
        } elseif ($request->boolean('remove_attachment')) {
            if ($ticket->attachment_path) {
                $oldFile = str_replace('/storage/', '', $ticket->attachment_path);
                \Storage::disk('public')->delete($oldFile);
            }
            $validated['attachment_path'] = null;
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
