<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Services\BookingService;

class SettingsController extends Controller
{
    public function index(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            abort(403, 'Unauthorized access.');
        }

        $settings = [
            'vat_enabled' => Setting::getValue('vat_enabled', '0') === '1',
            'vat_percent' => (float) Setting::getValue('vat_percent', '12'),
            'or_prefix' => Setting::getValue('or_prefix', 'OR'),
            'or_sequence' => (int) Setting::getValue('or_sequence', '1'),
        ];

        return Inertia::render('Settings/General', [
            'settings' => $settings,
        ]);
    }

    public function update(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            abort(403, 'Unauthorized access.');
        }

        $validated = $request->validate([
            'vat_enabled' => 'required|boolean',
            'vat_percent' => 'required|numeric|min:0|max:100',
            'or_prefix' => 'required|string|max:20',
            'or_sequence' => 'required|integer|min:1',
        ]);

        $oldVal = [
            'vat_enabled' => Setting::getValue('vat_enabled', '0'),
            'vat_percent' => Setting::getValue('vat_percent', '12'),
            'or_prefix' => Setting::getValue('or_prefix', 'OR'),
            'or_sequence' => Setting::getValue('or_sequence', '1'),
        ];

        Setting::setValue('vat_enabled', $validated['vat_enabled'] ? '1' : '0');
        Setting::setValue('vat_percent', $validated['vat_percent']);
        Setting::setValue('or_prefix', $validated['or_prefix']);
        Setting::setValue('or_sequence', $validated['or_sequence']);

        $newVal = [
            'vat_enabled' => $validated['vat_enabled'] ? '1' : '0',
            'vat_percent' => $validated['vat_percent'],
            'or_prefix' => $validated['or_prefix'],
            'or_sequence' => $validated['or_sequence'],
        ];

        BookingService::auditLog(
            $request->user()->id,
            'SETTINGS_UPDATED',
            'SETTINGS',
            null,
            $oldVal,
            $newVal
        );

        return redirect()->back()->with('success', 'General settings updated successfully.');
    }
}
