<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Services\ShiftService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceRegisterOperator
{
    /**
     * Personal account actions remain available in Viewer Mode.
     */
    private const PERSONAL_ROUTE_PATTERNS = [
        'logout',
        'profile.*',
        'password.*',
        'verification.*',
    ];

    /**
     * POST endpoints that calculate or validate without changing hotel data.
     */
    private const NON_MUTATING_ROUTE_PATTERNS = [
        'checkin.calculate',
        'reservations.calculate',
        'reservations.available_rooms',
        'promo_codes.validate',
    ];

    /**
     * Closed-shift accountability actions. Front Desk must be able to submit
     * a variance resolution after logging off the register.
     */
    private const ACCOUNTABILITY_ROUTE_PATTERNS = [
        'shifts.variances.store',
        'shifts.variances.record',
        'shifts.variances.approve',
        'shifts.variances.reject',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $request->isMethodSafe() || $this->isPersonalRoute($request) || $this->isAccountabilityRoute($request)) {
            return $next($request);
        }

        if (! UserRole::allowsOperational($user->role)) {
            return $next($request);
        }

        $activeRegister = ShiftService::activeRegister();

        if ($user->role === 'admin') {
            if ($activeRegister
                && $activeRegister->user_id !== $user->id
                && ! $request->routeIs(...self::NON_MUTATING_ROUTE_PATTERNS)) {
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'ADMIN_REGISTER_OVERRIDE',
                    'module' => 'shift_sessions',
                    'record_id' => $activeRegister->id,
                    'old_value' => (string) $activeRegister->user_id,
                    'new_value' => (string) $user->id,
                    'reason' => sprintf(
                        'Administrator used %s on route %s while the register was assigned to %s.',
                        $request->method(),
                        $request->route()?->getName() ?? $request->path(),
                        $activeRegister->user?->full_name ?? 'another staff member'
                    ),
                    'ip_address' => $request->ip(),
                ]);
            }

            return $next($request);
        }

        if ($request->routeIs('shifts.start') && $activeRegister === null) {
            return $next($request);
        }

        if ($activeRegister && $activeRegister->user_id === $user->id) {
            return $next($request);
        }

        $operatorName = $activeRegister?->user?->full_name;
        $message = $operatorName
            ? "Viewer Mode is read-only while {$operatorName} is assigned to the register."
            : 'Viewer Mode is read-only. Start the register shift before changing operational data.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        if ($activeRegister === null) {
            return redirect()->route('shifts.index')->with('warning', $message);
        }

        return back(fallback: route('shifts.index'))->with('warning', $message);
    }

    private function isAccountabilityRoute(Request $request): bool
    {
        return $request->routeIs(...self::ACCOUNTABILITY_ROUTE_PATTERNS);
    }

    private function isPersonalRoute(Request $request): bool
    {
        return $request->routeIs(...self::PERSONAL_ROUTE_PATTERNS)
            || $request->is('confirm-password');
    }
}
