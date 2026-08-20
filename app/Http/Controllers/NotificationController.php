<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Services\InventoryTurnoverService;
use App\Services\NotificationService;
use App\Services\ShiftVarianceResolutionService;
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

        $data = app()->environment('testing')
            ? $this->notifications->forUser($user)
            : Cache::remember('notifications.user_'.$user->id, 15, function () use ($user) {
                return $this->notifications->forUser($user);
            });

        // Bell items may be cached; variance banner always follows accounting state.
        $data['cash_variance_banner'] = app(ShiftVarianceResolutionService::class)->bannerForUser($user);
        $data['inventory_turnover_banner'] = app(InventoryTurnoverService::class)->bannerForUser($user);

        return response()->json($data);
    }
}
