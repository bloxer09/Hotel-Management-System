<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Services\ShiftService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $registerShift = $user ? ShiftService::activeRegister() : null;
        $ownsRegister = $user && $registerShift && $registerShift->user_id === $user->id;
        $viewerMode = $user
            && UserRole::isDeskStaff($user->role)
            && $registerShift
            && ! $ownsRegister;

        return [
            ...parent::share($request),
            'app_name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'active_shift' => $ownsRegister ? $registerShift : null,
                'register_shift' => $registerShift,
                'viewer_mode' => (bool) $viewerMode,
                'can_operate_register' => $user?->role === 'admin' || (bool) $ownsRegister,
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'warning' => $request->session()->get('warning'),
                'error' => $request->session()->get('error'),
            ],

        ];
    }
}
