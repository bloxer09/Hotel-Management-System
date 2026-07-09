<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\RoomType;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RoomController extends Controller
{
    public function index(Request $request)
    {
        $rooms = Room::with('type', 'activeBooking')
            ->orderBy('floor', 'asc')
            ->orderBy('room_number', 'asc')
            ->get();

        $roomTypes = RoomType::orderBy('type_name')->get();

        $housekeepers = \App\Models\User::whereIn('role', ['housekeeping', 'admin', 'front_desk'])
            ->orderBy('full_name')
            ->get(['id', 'full_name as name', 'role']);

        return Inertia::render('Rooms/Board', [
            'rooms'        => $rooms,
            'roomTypes'    => $roomTypes,
            'housekeepers' => $housekeepers,
        ]);
    }

    public function updateStatus(Room $room, Request $request)
    {
        $request->validate([
            'status' => 'required|in:vacant,occupied,cleaning,out_of_order',
            'notes'  => 'nullable|string',
            'assigned_housekeeper' => 'nullable|string|max:100',
        ]);

        $user = $request->user();
        $oldStatus = $room->status;
        $newStatus = $request->status;

        // Housekeeping constraints
        if ($user->role === 'housekeeping' && $room->status === 'occupied') {
            return back()->with('error', 'Housekeeping cannot update an occupied room. Use Check-Out first.');
        }
        if ($user->role === 'housekeeping' && !in_array($newStatus, ['vacant', 'cleaning', 'out_of_order'])) {
            return back()->with('error', 'Housekeeping can only change room status to Vacant, Cleaning, or Out of Order.');
        }
        if ($room->status === 'occupied' && $newStatus !== 'cleaning') {
            return back()->with('error', 'Cannot change occupied room status — use Check-Out first.');
        }

        $room->status = $newStatus;
        if ($request->has('notes')) {
            $room->notes = $request->notes;
        }

        if ($newStatus === 'cleaning') {
            if ($oldStatus !== 'cleaning') {
                $room->cleaning_started_at = now();
            }
            if ($request->has('assigned_housekeeper')) {
                $room->assigned_housekeeper = $request->assigned_housekeeper;
            }
        } else {
            $room->assigned_housekeeper = null;
            $room->cleaning_started_at = null;
        }

        $room->save();

        BookingService::auditLog(
            $user->id,
            'ROOM_STATUS_CHANGE',
            'rooms',
            $room->id,
            $oldStatus,
            $newStatus,
            "Room {$room->room_number} status changed. Notes: " . ($request->notes ?: 'None')
        );

        return back()->with('success', "Room {$room->room_number} status updated to " . ucfirst(str_replace('_', ' ', $newStatus)) . ".");
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            abort(403, 'Only admins can add rooms.');
        }

        $request->validate([
            'room_number'  => 'required|string|max:10|unique:rooms,room_number',
            'room_type_id' => 'required|exists:room_types,id',
            'floor'        => 'required|integer|min:1|max:99',
            'notes'        => 'nullable|string',
            'photo'        => 'nullable|image|max:4096',
        ]);

        $path = null;
        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store('rooms', 'public');
        }

        $room = Room::create([
            'room_number'  => strtoupper(trim($request->room_number)),
            'room_type_id' => $request->room_type_id,
            'floor'        => $request->floor,
            'status'       => 'vacant',
            'notes'        => $request->notes ?? '',
            'photo_path'   => $path,
        ]);

        BookingService::auditLog(
            $user->id,
            'ROOM_ADDED',
            'rooms',
            $room->id,
            null,
            "Room {$room->room_number} Floor {$room->floor}",
            'New room added'
        );

        return back()->with('success', "Room {$room->room_number} added successfully.");
    }

    public function update(Room $room, Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            abort(403, 'Only admins can edit rooms.');
        }
        if ($room->status === 'occupied') {
            return back()->with('error', 'Cannot edit an occupied room — check out the guest first.');
        }

        $request->validate([
            'room_type_id' => 'required|exists:room_types,id',
            'floor'        => 'required|integer|min:1|max:99',
            'notes'        => 'nullable|string',
            'perm_ooo'     => 'nullable|boolean',
            'status'       => 'nullable|in:vacant,occupied,cleaning,out_of_order',
            'photo'        => 'nullable|image|max:4096',
            'remove_photo' => 'nullable|boolean',
        ]);

        $oldData   = $room->toArray();
        // perm_ooo always wins; otherwise use explicit status or keep current
        $newStatus = $request->boolean('perm_ooo')
            ? 'out_of_order'
            : ($request->filled('status') ? $request->status : $room->status);

        if ($request->boolean('remove_photo')) {
            if ($room->photo_path) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($room->photo_path);
            }
            $room->photo_path = null;
        } elseif ($request->hasFile('photo')) {
            if ($room->photo_path) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($room->photo_path);
            }
            $path = $request->file('photo')->store('rooms', 'public');
            $room->photo_path = $path;
        }

        $room->update([
            'room_type_id' => $request->room_type_id,
            'floor'        => $request->floor,
            'notes'        => $request->notes ?? '',
            'status'       => $newStatus,
        ]);

        BookingService::auditLog(
            $user->id,
            'ROOM_EDITED',
            'rooms',
            $room->id,
            json_encode(['type' => $oldData['room_type_id'], 'floor' => $oldData['floor'], 'status' => $oldData['status']]),
            json_encode(['type' => $room->room_type_id, 'floor' => $room->floor, 'status' => $room->status]),
            $request->notes ?: 'Room details updated'
        );

        return back()->with('success', "Room {$room->room_number} updated.");
    }

    public function destroy(Room $room, Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            abort(403, 'Only admins can delete rooms.');
        }
        if ($room->status === 'occupied') {
            return back()->with('error', 'Cannot delete an occupied room.');
        }

        $roomNumber = $room->room_number;

        try {
            $room->delete();

            BookingService::auditLog(
                $user->id,
                'ROOM_DELETED',
                'rooms',
                $room->id,
                $roomNumber,
                null,
                'Room deleted'
            );
        } catch (\Exception $e) {
            return back()->with('error', 'Cannot delete this room because it has associated booking history.');
        }

        return back()->with('success', "Room {$roomNumber} was successfully deleted.");
    }

    public function bulkClean(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'front_desk', 'housekeeping'])) {
            abort(403, 'Unauthorized.');
        }

        $roomIds = $request->input('room_ids', []);
        $count = 0;

        foreach ($roomIds as $rid) {
            $rid = (int) $rid;
            $room = Room::find($rid);
            if ($room && $room->status === 'cleaning') {
                $room->update([
                    'status' => 'vacant', 
                    'notes' => '',
                    'assigned_housekeeper' => null,
                    'cleaning_started_at' => null,
                ]);
                BookingService::auditLog($user->id, 'ROOM_BULK_CLEAN', 'rooms', $room->id, 'cleaning', 'vacant', 'Bulk housekeeping');
                $count++;
            }
        }

        return back()->with('success', "{$count} room(s) marked as Vacant / Ready.");
    }
}
