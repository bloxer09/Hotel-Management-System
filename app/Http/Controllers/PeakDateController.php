<?php

namespace App\Http\Controllers;

use App\Models\PeakDate;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PeakDateController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            abort(403, 'Unauthorized access.');
        }

        $peakDates = PeakDate::orderBy('date_from', 'asc')->get();

        return Inertia::render('Settings/Peaks', [
            'peakDates' => $peakDates,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'label' => 'required|string|max:100',
            'surcharge_amount' => 'required|numeric|min:0',
            'surcharge_type' => 'required|in:fixed,percent',
        ]);

        $user = $request->user();
        if ($user->role !== 'admin') {
            abort(403, 'Unauthorized access.');
        }

        // Check for active overlapping peak dates
        $overlap = PeakDate::where('is_active', true)
            ->where(function($query) use ($request) {
                $query->whereBetween('date_from', [$request->date_from, $request->date_to])
                    ->orWhereBetween('date_to', [$request->date_from, $request->date_to])
                    ->orWhere(function($q2) use ($request) {
                        $q2->where('date_from', '<=', $request->date_from)
                           ->where('date_to', '>=', $request->date_to);
                    });
            })
            ->first();

        if ($overlap) {
            return back()->withErrors(['date_from' => "The selected date range overlaps with an existing active peak date range: '{$overlap->label}' ({$overlap->date_from} to {$overlap->date_to})."]);
        }

        $peakDate = PeakDate::create([
            'date_from' => $request->date_from,
            'date_to' => $request->date_to,
            'label' => $request->label,
            'surcharge_amount' => $request->surcharge_amount,
            'surcharge_type' => $request->surcharge_type,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        BookingService::auditLog(
            $user->id,
            'PEAK_DATE_CREATE',
            'peak_dates',
            $peakDate->id,
            null,
            $peakDate->label,
            "Created peak surcharge date: {$peakDate->label} from {$request->date_from} to {$request->date_to}."
        );

        return back()->with('success', "Peak date '{$request->label}' created successfully.");
    }

    public function toggle(PeakDate $peakDate, Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            abort(403, 'Unauthorized access.');
        }

        $oldVal = $peakDate->is_active;
        $peakDate->is_active = !$peakDate->is_active;
        $peakDate->save();

        BookingService::auditLog(
            $user->id,
            'PEAK_DATE_TOGGLE',
            'peak_dates',
            $peakDate->id,
            $oldVal ? 'Active' : 'Inactive',
            $peakDate->is_active ? 'Active' : 'Inactive',
            "Toggled peak date status for '{$peakDate->label}'."
        );

        return back()->with('success', "Peak date '{$peakDate->label}' updated successfully.");
    }

    public function destroy(PeakDate $peakDate, Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            abort(403, 'Unauthorized access.');
        }

        $label = $peakDate->label;
        $peakDate->delete();

        BookingService::auditLog(
            $user->id,
            'PEAK_DATE_DELETE',
            'peak_dates',
            $peakDate->id,
            $label,
            null,
            "Deleted peak date '{$label}'."
        );

        return back()->with('success', "Peak date '{$label}' deleted successfully.");
    }
}
