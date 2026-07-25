<?php

namespace App\Http\Controllers;

use App\Models\RoomType;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RoomRateController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            abort(403, 'Unauthorized access to rates settings.');
        }

        $query = RoomType::withCount('rooms');

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('type_name', 'like', $search)
                  ->orWhere('description', 'like', $search)
                  ->orWhere('amenities', 'like', $search);
            });
        }

        $sortBy = $request->input('sort_by', 'id');
        $sortDir = $request->input('sort_dir', 'asc');

        $allowedSorts = ['id', 'type_name', 'base_rate', 'max_occupancy', 'rooms_count'];
        if (!in_array($sortBy, $allowedSorts)) $sortBy = 'id';
        if (!in_array($sortDir, ['asc', 'desc'])) $sortDir = 'asc';

        $roomTypes = $query->orderBy($sortBy, $sortDir)->paginate(10)->withQueryString();

        return Inertia::render('Settings/Rates', [
            'roomTypes' => $roomTypes,
            'filters' => $request->only(['search']),
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
        ]);
    }

    public function update(RoomType $roomType, Request $request)
    {
        $request->validate([
            'base_rate' => 'required|numeric|min:0',
            'hourly_rate' => 'required|numeric|min:0',
            'short_time_3h_rate' => 'required|numeric|min:0',
            'short_time_6h_rate' => 'required|numeric|min:0',
            'short_time_12h_rate' => 'required|numeric|min:0',
            'short_time_24h_rate' => 'required|numeric|min:0',
            'max_occupancy' => 'required|integer|min:1',
            'description' => 'nullable|string',
            'amenities' => 'nullable|string',
            'photo' => 'nullable|image|max:4096',
        ]);

        $user = $request->user();
        if ($user->role !== 'admin') {
            abort(403, 'Unauthorized access to rates settings.');
        }

        $oldRates = $roomType->toArray();
        
        $roomType->fill($request->except('photo'));

        if ($request->hasFile('photo')) {
            // Delete old photo if exists
            if ($roomType->photo_path) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($roomType->photo_path);
            }
            $path = $request->file('photo')->store('room_types', 'public');
            $roomType->photo_path = $path;
        }

        $roomType->save();
        $newRates = $roomType->toArray();

        BookingService::auditLog(
            $user->id,
            'ROOM_RATES_UPDATE',
            'room_types',
            $roomType->id,
            $oldRates,
            $newRates,
            "Updated rate configuration for room type {$roomType->type_name}."
        );

        return back()->with('success', "Rates for {$roomType->type_name} updated successfully.");
    }
    
    public function store(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            abort(403, 'Only admins can add room types.');
        }

        $request->validate([
            'type_name'           => 'required|string|max:100|unique:room_types,type_name',
            'description'         => 'nullable|string',
            'base_rate'           => 'required|numeric|min:0',
            'hourly_rate'         => 'nullable|numeric|min:0',
            'short_time_3h_rate'  => 'required|numeric|min:0',
            'short_time_6h_rate'  => 'required|numeric|min:0',
            'short_time_12h_rate' => 'required|numeric|min:0',
            'short_time_24h_rate' => 'required|numeric|min:0',
            'max_occupancy'       => 'required|integer|min:1|max:20',
            'amenities'           => 'nullable|string',
            'photo'               => 'nullable|image|max:4096',
        ]);

        $hourlyRate = $request->hourly_rate;
        if (!$hourlyRate && $request->short_time_3h_rate > 0) {
            $hourlyRate = round($request->short_time_3h_rate / 3, 2);
        }

        $roomType = RoomType::create([
            'type_name'           => $request->type_name,
            'description'         => $request->description,
            'base_rate'           => $request->base_rate,
            'hourly_rate'         => $hourlyRate ?? 0,
            'short_time_3h_rate'  => $request->short_time_3h_rate,
            'short_time_6h_rate'  => $request->short_time_6h_rate,
            'short_time_12h_rate' => $request->short_time_12h_rate,
            'short_time_24h_rate' => $request->short_time_24h_rate,
            'max_occupancy'       => $request->max_occupancy,
            'amenities'           => $request->amenities,
        ]);

        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store('room_types', 'public');
            $roomType->photo_path = $path;
            $roomType->save();
        }

        BookingService::auditLog(
            $user->id,
            'ROOM_TYPE_ADDED',
            'room_types',
            $roomType->id,
            null,
            $roomType->type_name,
            'New room type added'
        );

        return back()->with('success', "Room type '{$roomType->type_name}' added successfully.");
    }

    public function destroy(RoomType $roomType, Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            abort(403, 'Only admins can delete room types.');
        }

        $roomCount = $roomType->rooms()->count();
        if ($roomCount > 0) {
            return back()->with('error', 'Cannot delete this room type because it is assigned to existing rooms.');
        }

        $typeName = $roomType->type_name;

        BookingService::auditLog(
            $user->id,
            'ROOM_TYPE_DELETED',
            'room_types',
            $roomType->id,
            $typeName,
            null,
            'Room type deleted by admin'
        );

        $roomType->delete();

        return back()->with('success', "Room type '{$typeName}' deleted.");
    }
}

