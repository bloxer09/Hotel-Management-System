<?php

namespace App\Http\Middleware;

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
        return [
            ...parent::share($request),
            'app_name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'active_shift' => $request->user() 
                    ? \Illuminate\Support\Facades\Cache::remember(
                        "active_shift_{$request->user()->id}", 
                        now()->addMinutes(5), 
                        fn() => \App\Models\ShiftSession::where('user_id', $request->user()->id)->whereNull('ended_at')->first()
                    )
                    : null,
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'warning' => $request->session()->get('warning'),
                'error' => $request->session()->get('error'),
            ],

        ];
    }
}
