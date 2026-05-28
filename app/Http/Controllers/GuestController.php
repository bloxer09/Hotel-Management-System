<?php

namespace App\Http\Controllers;

use App\Models\GuestProfile;
use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GuestController extends Controller
{
    public function index(Request $request)
    {
        $search    = $request->input('search');
        $vipFilter = $request->input('vip'); // '1', '0', or null = all

        $query = GuestProfile::orderBy('total_stays', 'desc')
            ->when($search, function ($q, $search) {
                return $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('contact_number', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            })
            ->when($vipFilter !== null && $vipFilter !== '', function ($q) use ($vipFilter) {
                return $q->where('is_vip', (bool) $vipFilter);
            });

        $guests = $query->get();

        $totalCount   = GuestProfile::count();
        $vipCount     = GuestProfile::where('is_vip', true)->count();
        $regularCount = $totalCount - $vipCount;

        return Inertia::render('Guests/Index', [
            'guests'       => $guests,
            'currentSearch' => $search,
            'currentVip'   => $vipFilter,
            'stats'        => compact('totalCount', 'vipCount', 'regularCount'),
        ]);
    }

    public function search(Request $request)
    {
        $query = $request->input('q', '');
        if (strlen($query) < 2) {
            return response()->json([]);
        }

        $guests = GuestProfile::where('full_name', 'like', "%{$query}%")
            ->orderBy('full_name', 'asc')
            ->limit(10)
            ->get();

        return response()->json($guests);
    }

    public function show(GuestProfile $guest)
    {
        $bookings = Booking::with(['room', 'room.type'])
            ->where('guest_profile_id', $guest->id)
            ->orderBy('id', 'desc')
            ->get();

        return Inertia::render('Guests/Show', [
            'guest' => $guest,
            'bookings' => $bookings,
        ]);
    }

    public function toggleVip(GuestProfile $guest, Request $request)
    {
        $request->validate([
            'is_vip' => 'required|boolean',
            'vip_notes' => 'nullable|string',
        ]);

        $user = $request->user();

        // Enforce Admin constraint
        if ($user->role !== 'admin') {
            abort(403, 'Only administrators can alter VIP guest settings.');
        }

        $oldVip = $guest->is_vip;
        $guest->is_vip = $request->is_vip;
        $guest->vip_notes = $request->vip_notes;
        $guest->save();

        BookingService::auditLog(
            $user->id,
            'VIP_TOGGLE',
            'guest_profiles',
            $guest->id,
            $oldVip ? 'VIP' : 'Normal',
            $request->is_vip ? 'VIP' : 'Normal',
            "Toggled VIP status of guest {$guest->full_name}. Notes: " . ($request->vip_notes ?: 'None')
        );

        return back()->with('success', "Guest {$guest->full_name} status updated.");
    }

    public function sync(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'admin') {
            abort(403, 'Only administrators can run guest profile sync.');
        }

        $bookings = Booking::where('status', 'checked_out')
            ->whereNotNull('guest_contact')
            ->where('guest_contact', '!=', '')
            ->selectRaw('guest_name, guest_contact, guest_id_type, guest_id_number, COUNT(*) as stays, SUM(amount_paid) as spent, MAX(check_in) as last_visit')
            ->groupBy('guest_contact', 'guest_name', 'guest_id_type', 'guest_id_number')
            ->get();

        $synced = 0;
        foreach ($bookings as $b) {
            $profile = GuestProfile::where('contact_number', $b->guest_contact)->first();
            if (!$profile) {
                GuestProfile::create([
                    'full_name' => $b->guest_name,
                    'contact_number' => $b->guest_contact,
                    'id_type' => $b->guest_id_type ?: 'None',
                    'id_number' => $b->guest_id_number ?: 'None',
                    'total_stays' => $b->stays,
                    'total_spent' => $b->spent,
                    'last_visit' => $b->last_visit,
                ]);
                $synced++;
            } else {
                $profile->update([
                    'total_stays' => $b->stays,
                    'total_spent' => $b->spent,
                    'last_visit' => $b->last_visit,
                ]);
            }
        }

        BookingService::auditLog(
            $user->id,
            'GUEST_SYNC',
            'guest_profiles',
            0,
            'N/A',
            'Synced',
            "Synchronized guest profiles from booking history. Added {$synced} new profiles."
        );

        return back()->with('success', "Synced guest profiles from bookings. Created {$synced} new guest profiles.");
    }
}
