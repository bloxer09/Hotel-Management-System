<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\ShiftSession;

class ActiveShiftMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && in_array($user->role, ['front_desk', 'cashier'])) {
            $hasActiveShift = ShiftSession::where('user_id', $user->id)
                ->whereNull('ended_at')
                ->exists();

            if (!$hasActiveShift) {
                // If it is an Inertia request, return with Inertia flash warning
                if ($request->header('X-Inertia')) {
                    return back()->with('warning', 'You must start your shift before performing any transaction operations.');
                }
                
                // Allow shift related endpoints so they can start their shift!
                if ($request->routeIs('shifts.*')) {
                    return $next($request);
                }

                return redirect()->route('shifts.index')->with('warning', 'You must start your shift before performing any transaction operations.');
            }
        }

        return $next($request);
    }
}
