<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\ApproveShiftVarianceResolutionRequest;
use App\Http\Requests\RecordShiftVarianceResolutionRequest;
use App\Http\Requests\RejectShiftVarianceResolutionRequest;
use App\Http\Requests\StoreShiftVarianceResolutionRequest;
use App\Models\ShiftSession;
use App\Models\ShiftVarianceResolution;
use App\Services\ShiftCashReconciliationService;
use App\Services\ShiftVarianceResolutionService;
use App\Support\HotelDateTime;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ShiftVarianceController extends Controller
{
    public function __construct(
        private readonly ShiftVarianceResolutionService $resolutions
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        if ($user->role !== UserRole::Admin->value) {
            abort(403, 'Unauthorized access.');
        }

        $filter = (string) $request->query('filter', 'pending');
        if (! in_array($filter, ['pending', 'partial', 'resolved', 'all'], true)) {
            $filter = 'pending';
        }

        $query = ShiftSession::with('user')
            ->whereNotNull('ended_at')
            ->whereNotNull('expected_formula_version')
            ->where(function ($inner) {
                $inner->whereRaw('ABS(COALESCE(variance_rooms, 0)) >= ?', [ShiftCashReconciliationService::TOLERANCE])
                    ->orWhereRaw('ABS(COALESCE(variance_minibar, 0)) >= ?', [ShiftCashReconciliationService::TOLERANCE]);
            })
            ->orderByDesc('ended_at');

        match ($filter) {
            'pending' => $query->where('variance_status', ShiftVarianceResolutionService::STATUS_PENDING_REVIEW),
            'partial' => $query->where('variance_status', ShiftVarianceResolutionService::STATUS_PARTIALLY_RESOLVED),
            'resolved' => $query->where('variance_status', ShiftVarianceResolutionService::STATUS_RESOLVED),
            default => $query->whereIn('variance_status', [
                ShiftVarianceResolutionService::STATUS_PENDING_REVIEW,
                ShiftVarianceResolutionService::STATUS_PARTIALLY_RESOLVED,
                ShiftVarianceResolutionService::STATUS_RESOLVED,
            ]),
        };

        $shifts = $query->limit(200)->get();
        $rows = [];
        foreach ($shifts as $shift) {
            foreach ([ShiftVarianceResolution::DRAWER_ROOM, ShiftVarianceResolution::DRAWER_MINIBAR] as $drawer) {
                $review = $this->resolutions->drawerReview($shift, $drawer);
                if (abs((float) $review['original_variance']) < ShiftCashReconciliationService::TOLERANCE) {
                    continue;
                }
                $rows[] = [
                    'shift_id' => (int) $shift->id,
                    'shift_code' => $shift->shift_code,
                    'front_desk' => $shift->user?->full_name,
                    'closed_at' => HotelDateTime::utcIso($shift->ended_at),
                    'closed_at_display' => HotelDateTime::formatUtcForDisplay($shift->ended_at),
                    'overall_status' => $shift->variance_status,
                    'review_url' => route('shifts.report', $shift->id, false).'?tab=variance',
                    ...$review,
                ];
            }
        }

        return Inertia::render('Shifts/Variances', [
            'filter' => $filter,
            'rows' => $rows,
        ]);
    }

    public function store(StoreShiftVarianceResolutionRequest $request, ShiftSession $shift)
    {
        $this->resolutions->assertCanView($shift, $request->user());
        $this->resolutions->submit($shift, $request->user(), $request->validated());

        return back()->with('success', 'Variance resolution submitted for Admin review.');
    }

    public function record(RecordShiftVarianceResolutionRequest $request, ShiftSession $shift)
    {
        $this->resolutions->recordApproved($shift, $request->user(), $request->validated());

        return back()->with('success', 'Variance resolution recorded and approved.');
    }

    public function approve(ApproveShiftVarianceResolutionRequest $request, ShiftVarianceResolution $resolution)
    {
        $this->resolutions->approve(
            $resolution,
            $request->user(),
            $request->input('review_notes'),
            $request->boolean('receive_into_active_drawer'),
            $request->input('recovery_destination')
        );

        return back()->with('success', 'Variance resolution approved.');
    }

    public function reject(RejectShiftVarianceResolutionRequest $request, ShiftVarianceResolution $resolution)
    {
        $this->resolutions->reject(
            $resolution,
            $request->user(),
            (string) $request->input('review_notes')
        );

        return back()->with('success', 'Variance resolution rejected.');
    }
}
