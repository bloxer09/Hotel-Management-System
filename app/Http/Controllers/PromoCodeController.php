<?php

namespace App\Http\Controllers;

use App\Models\PromoCode;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Services\BookingService;

class PromoCodeController extends Controller
{
    public function index(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            abort(403, 'Unauthorized access.');
        }

        $promoCodes = PromoCode::with('creator')
            ->orderBy('id', 'desc')
            ->get();

        return Inertia::render('Settings/PromoCodes', [
            'promoCodes' => $promoCodes,
        ]);
    }

    public function store(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            abort(403, 'Unauthorized access.');
        }

        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:promo_codes',
            'label' => 'required|string|max:100',
            'discount_type' => 'required|in:fixed,percent',
            'discount_value' => 'required|numeric|min:0',
            'max_uses' => 'nullable|integer|min:1',
            'expires_at' => 'nullable|date|after_or_equal:today',
            'is_active' => 'required|boolean',
        ]);

        $validated['created_by'] = $request->user()->id;

        $promoCode = PromoCode::create($validated);

        BookingService::auditLog(
            $request->user()->id,
            'PROMO_CODE_CREATED',
            'PROMO_CODES',
            $promoCode->id,
            null,
            $promoCode
        );

        return redirect()->back()->with('success', 'Promo code created successfully.');
    }

    public function update(Request $request, PromoCode $promoCode)
    {
        if ($request->user()->role !== 'admin') {
            abort(403, 'Unauthorized access.');
        }

        $validated = $request->validate([
            'code' => 'required|string|max:50|unique:promo_codes,code,' . $promoCode->id,
            'label' => 'required|string|max:100',
            'discount_type' => 'required|in:fixed,percent',
            'discount_value' => 'required|numeric|min:0',
            'max_uses' => 'nullable|integer|min:1',
            'expires_at' => 'nullable|date',
            'is_active' => 'required|boolean',
        ]);

        $oldVal = $promoCode->toArray();
        $promoCode->update($validated);

        BookingService::auditLog(
            $request->user()->id,
            'PROMO_CODE_UPDATED',
            'PROMO_CODES',
            $promoCode->id,
            $oldVal,
            $promoCode
        );

        return redirect()->back()->with('success', 'Promo code updated successfully.');
    }

    public function destroy(Request $request, PromoCode $promoCode)
    {
        if ($request->user()->role !== 'admin') {
            abort(403, 'Unauthorized access.');
        }

        $oldVal = $promoCode->toArray();
        $promoCode->delete();

        BookingService::auditLog(
            $request->user()->id,
            'PROMO_CODE_DELETED',
            'PROMO_CODES',
            $promoCode->id,
            $oldVal,
            null
        );

        return redirect()->back()->with('success', 'Promo code deleted successfully.');
    }

    public function validateCode(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $promoCode = PromoCode::where('code', $request->code)->first();

        if (!$promoCode) {
            return response()->json([
                'valid' => false,
                'message' => 'Invalid promo code.'
            ]);
        }

        if (!$promoCode->isValid()) {
            return response()->json([
                'valid' => false,
                'message' => 'This promo code has expired or is inactive.'
            ]);
        }

        return response()->json([
            'valid' => true,
            'code' => $promoCode->code,
            'label' => $promoCode->label,
            'discount_type' => $promoCode->discount_type,
            'discount_value' => (float)$promoCode->discount_value
        ]);
    }
}
