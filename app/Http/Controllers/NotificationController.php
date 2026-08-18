<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Return role-specific checkout, room, and operational alerts.
     */
    public function getNotifications(Request $request)
    {
        $user = $request->user();
        if (! $user || ! UserRole::canReceiveNotifications($user->role)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.',
            ], 403);
        }

        if (app()->environment('testing')) {
            return response()->json($this->notifications->forUser($user));
        }

        $data = Cache::remember('notifications.user_'.$user->id, 15, function () use ($user) {
            return $this->notifications->forUser($user);
        });

        return response()->json($data);
    }
}
