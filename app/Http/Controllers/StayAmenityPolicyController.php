<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\StayAmenityPolicy;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class StayAmenityPolicyController extends Controller
{
    public function index(Request $request)
    {
        $this->assertAdmin($request);

        $policies = StayAmenityPolicy::query()
            ->with(['item' => fn ($query) => $query->withTrashed()])
            ->orderBy('stay_key')
            ->orderBy('id')
            ->get();

        $items = InventoryItem::query()
            ->where('is_active', true)
            ->orderBy('item_name')
            ->get(['id', 'item_name', 'category', 'unit', 'current_stock']);

        return Inertia::render('Settings/AmenityPolicies', [
            'policies' => $policies,
            'inventoryItems' => $items,
            'stayKeys' => [
                ['id' => StayAmenityPolicy::STAY_OVERNIGHT, 'name' => StayAmenityPolicy::stayKeyLabel(StayAmenityPolicy::STAY_OVERNIGHT)],
                ['id' => StayAmenityPolicy::STAY_SHORT_TIME_24, 'name' => StayAmenityPolicy::stayKeyLabel(StayAmenityPolicy::STAY_SHORT_TIME_24)],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->assertAdmin($request);
        $data = $this->validated($request);

        $policy = StayAmenityPolicy::create($data);

        BookingService::auditLog(
            $request->user()->id,
            'AMENITY_POLICY_CREATE',
            'stay_amenity_policies',
            $policy->id,
            null,
            $policy->stay_key,
            'Configured complimentary amenity policy for '.StayAmenityPolicy::stayKeyLabel($policy->stay_key).'.'
        );

        return back()->with('success', 'Complimentary amenity policy saved.');
    }

    public function update(Request $request, StayAmenityPolicy $stayAmenityPolicy)
    {
        $this->assertAdmin($request);
        $data = $this->validated($request, $stayAmenityPolicy->id);

        $stayAmenityPolicy->update($data);

        BookingService::auditLog(
            $request->user()->id,
            'AMENITY_POLICY_UPDATE',
            'stay_amenity_policies',
            $stayAmenityPolicy->id,
            null,
            $stayAmenityPolicy->stay_key,
            'Updated complimentary amenity policy for '.StayAmenityPolicy::stayKeyLabel($stayAmenityPolicy->stay_key).'.'
        );

        return back()->with('success', 'Complimentary amenity policy updated.');
    }

    public function destroy(Request $request, StayAmenityPolicy $stayAmenityPolicy)
    {
        $this->assertAdmin($request);

        $stayKey = $stayAmenityPolicy->stay_key;
        $stayAmenityPolicy->delete();

        BookingService::auditLog(
            $request->user()->id,
            'AMENITY_POLICY_DELETE',
            'stay_amenity_policies',
            $stayAmenityPolicy->id,
            $stayKey,
            null,
            'Removed complimentary amenity policy for '.StayAmenityPolicy::stayKeyLabel($stayKey).'.'
        );

        return back()->with('success', 'Complimentary amenity policy removed.');
    }

    private function validated(Request $request, ?int $exceptId = null): array
    {
        $data = $request->validate([
            'stay_key' => 'required|in:'.implode(',', StayAmenityPolicy::STAY_KEYS),
            'inventory_item_id' => 'required|integer|exists:inventory_items,id',
            'default_quantity' => 'required|integer|min:1|max:99',
            'is_active' => 'sometimes|boolean',
        ]);

        $duplicate = StayAmenityPolicy::query()
            ->where('stay_key', $data['stay_key'])
            ->where('inventory_item_id', $data['inventory_item_id'])
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'inventory_item_id' => 'That product is already configured for this stay type.',
            ]);
        }

        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    private function assertAdmin(Request $request): void
    {
        if ($request->user()?->role !== 'admin') {
            abort(403, 'Only administrators can configure complimentary amenity policies.');
        }
    }
}
