<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceTicket;
use App\Models\Room;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Services\BookingService;
use Carbon\Carbon;

class MaintenanceController extends Controller
{
    public function index(Request $request)
    {
        $sortBy = $request->input('sort_by', 'id');
        $sortDir = $request->input('sort_dir', 'desc');
        $search = $request->input('search');
        $status = $request->input('status', 'all');
        $from = $request->input('from');
        $to = $request->input('to');

        $allowedSorts = ['id', 'room_id', 'title', 'status', 'priority', 'created_at'];
        if (!in_array($sortBy, $allowedSorts)) $sortBy = 'id';
        if (!in_array($sortDir, ['asc', 'desc'])) $sortDir = 'desc';

        $query = MaintenanceTicket::with(['room.type', 'reportedBy', 'resolvedBy', 'verifiedBy']);

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($from) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('created_at', '<=', $to);
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
            'filters' => ['search' => $search, 'status' => $status, 'from' => $from, 'to' => $to],
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
            'created_at' => 'nullable|date',
        ]);

        $validated['reported_by'] = $request->user()->id;
        $validated['status'] = 'open';

        if ($request->filled('created_at')) {
            $validated['created_at'] = Carbon::parse($request->created_at);
        }

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
            'status' => 'nullable|in:open,in_progress,for_verification,closed',
            'notes' => 'nullable|string',
            'priority' => 'nullable|in:low,medium,high,critical',
            'room_id' => 'nullable|exists:rooms,id',
            'title' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'attachment' => 'nullable|file|max:10240', // Max 10MB
            'remove_attachment' => 'nullable|boolean',
            'resolution_notes' => 'nullable|string|max:5000',
            'repaired_by' => 'nullable|string|max:150',
            'repaired_at' => 'nullable|date',
            'repair_cost' => 'nullable|numeric|min:0',
            'receipt_reference' => 'nullable|string|max:100',
            'receipt_attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:10240',
            'after_repair_attachment' => 'nullable|image|mimes:jpg,jpeg,png|max:10240',
        ]);

        if (($validated['status'] ?? null) === 'for_verification') {
            $request->validate([
                'resolution_notes' => 'required|string|max:5000',
                'repaired_by' => 'required|string|max:150',
                'repaired_at' => 'required|date',
                'repair_cost' => 'required|numeric|min:0',
            ]);
        }

        // Only an Admin may verify and close a completed repair.
        if (($validated['status'] ?? null) === 'closed') {
            if ($user->role !== 'admin') {
                abort(403, 'Only an Admin can verify and close maintenance tickets.');
            }

            if ($ticket->status !== 'for_verification') {
                abort(422, 'A maintenance ticket must be submitted for verification before it can be closed.');
            }

            $validated['resolved_by'] = $user->id;
            $validated['resolved_at'] = now();
            $validated['verified_by'] = $user->id;
            $validated['verified_at'] = now();

            // Auto-transition room to cleaning if currently out of order
            $room = $ticket->room;
            if ($room && $room->status === 'out_of_order') {
                $room->status = 'cleaning';
                $room->assigned_housekeeper = null;
                $room->cleaning_started_at = now();
                $room->save();
            }
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

        foreach ([
            'receipt_attachment' => 'receipt_attachment_path',
            'after_repair_attachment' => 'after_repair_attachment_path',
        ] as $uploadField => $pathColumn) {
            if ($request->hasFile($uploadField)) {
                if ($ticket->{$pathColumn}) {
                    $oldFile = str_replace('/storage/', '', $ticket->{$pathColumn});
                    \Storage::disk('public')->delete($oldFile);
                }

                $path = $request->file($uploadField)->store('maintenance', 'public');
                $validated[$pathColumn] = '/storage/' . $path;
            }
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
