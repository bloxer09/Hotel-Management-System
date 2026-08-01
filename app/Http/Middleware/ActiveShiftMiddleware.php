<?php

namespace App\Http\Middleware;

use App\Services\ShiftService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ActiveShiftMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && in_array($user->role, ['front_desk', 'cashier'])) {
            // Viewer accounts may inspect operational pages. Mutations still
            // require ownership of the hotel's single front-desk register.
            if ($request->isMethodSafe()) {
                return $next($request);
            }

            if (! ShiftService::requireActiveShift($user)) {
                // If it is an Inertia request, return with Inertia flash warning
                if ($request->header('X-Inertia')) {
                    return back()->with('warning', 'Viewer Mode is read-only. Only the assigned register operator may perform this action.');
                }

                // Allow shift related endpoints so they can start their shift!
                if ($request->routeIs('shifts.*')) {
                    return $next($request);
                }

                return redirect()->route('shifts.index')->with('warning', 'Viewer Mode is read-only. Only the assigned register operator may perform this action.');
            }
        }

        return $next($request);
    }
}
