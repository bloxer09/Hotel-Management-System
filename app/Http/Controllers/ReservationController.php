<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReservationRequest;
use App\Models\Booking;
use App\Models\GuestProfile;
use App\Models\InventoryUsage;
use App\Models\PromoCode;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\ShiftSession;
use App\Models\Transaction;
use App\Services\BookingService;
use App\Services\PaymentService;
use App\Services\ShiftService;
use App\Support\HotelDateTime;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ReservationController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments
    ) {}

    public function index(Request $request)
    {
        $status = $request->input('status', 'reserved');
        if ($status === 'groups') {
            $status = 'all';
        }

        $sortBy = $request->input('sort_by', 'id');
        $sortDir = $request->input('sort_dir', 'desc');
        $showGroupsOnly = $request->boolean('show_groups_only', false);
        $dateScope = $request->input('date_scope');
        if (! in_array($dateScope, ['arrivals_today'], true)) {
            $dateScope = null;
        }
        [$todayStart, $todayEnd] = HotelDateTime::dayWindow();

        $allowedSorts = ['id', 'guest_name', 'status', 'check_in_time', 'expected_check_out', 'amount'];
        if (! in_array($sortBy, $allowedSorts)) {
            $sortBy = 'id';
        }
        if (! in_array($sortDir, ['asc', 'desc'])) {
            $sortDir = 'desc';
        }

        $pendingPaymentSum = [
            'paymentAllocations as pending_payment_amount' => fn ($q) => $q
                ->whereHas('payment', fn ($payment) => $payment->where('status', 'pending')),
        ];

        $reservations = Booking::with(['room', 'room.type'])
            ->withSum($pendingPaymentSum, 'allocated_amount')
            ->when($status && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($dateScope === 'arrivals_today', fn ($q) => $q->whereBetween('check_in', [$todayStart, $todayEnd]))
            ->when($showGroupsOnly, fn ($q) => $q->whereNotNull('group_ref'))
            ->orderBy($sortBy, $sortDir)
            ->paginate(15)
            ->withQueryString();

        $groupBookings = Booking::getActiveGroupsMap($pendingPaymentSum);

        // Wizard data for the New Booking modal
        $rooms = Room::with('type')
            ->orderBy('room_number', 'asc')
            ->get();

        $promoCodes = PromoCode::available()
            ->orderBy('code', 'asc')
            ->get(['code', 'discount_type', 'discount_value']);

        $calendarMonth = $request->input('calendar_month', HotelDateTime::now()->format('Y-m'));
        try {
            [$calendarStart, $calendarEnd] = HotelDateTime::monthWindow($calendarMonth);
        } catch (\Throwable $e) {
            [$calendarStart, $calendarEnd] = HotelDateTime::monthWindow();
            $calendarMonth = HotelDateTime::now()->format('Y-m');
        }

        // Include every stay that overlaps the month, not only arrivals in the month.
        // This prevents a multi-night stay from looking available after its check-in date.
        $calendarBookings = Booking::with(['room', 'room.type'])
            ->whereIn('status', ['reserved', 'active'])
            ->where('check_in', '<=', $calendarEnd)
            ->where('expected_check_out', '>', $calendarStart)
            ->orderBy('check_in')
            ->orderBy('room_id')
            ->get();

        return Inertia::render('Reservations/Index', [
            'reservations' => $reservations,
            'groupBookings' => (object) $groupBookings,
            'currentFilter' => $status,
            'dateScope' => $dateScope,
            'showGroupsOnly' => $showGroupsOnly,
            'rooms' => $rooms,
            'promoCodes' => $promoCodes,
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
            'calendarBookings' => $calendarBookings,
            'calendarMonth' => $calendarMonth,
            'calendarView' => $request->input('view') === 'calendar',
        ]);
    }

    public function create(Request $request)
    {
        // Load all rooms so staff can select any room for future booking
        $rooms = Room::with('type')
            ->orderBy('room_number', 'asc')
            ->get();

        $roomTypes = RoomType::all();

        $prefilledGuest = null;
        if ($request->has('guest_id')) {
            $prefilledGuest = GuestProfile::find($request->input('guest_id'));
        }

        $promoCodes = PromoCode::available()
            ->orderBy('code', 'asc')
            ->get(['code', 'discount_type', 'discount_value']);

        return Inertia::render('Reservations/Create', [
            'rooms' => $rooms,
            'roomTypes' => $roomTypes,
            'prefilledGuest' => $prefilledGuest,
            'promoCodes' => $promoCodes,
        ]);
    }

    public function calculate(Request $request)
    {
        $request->validate([
            'room_ids' => 'required|array|min:1',
            'room_ids.*' => 'exists:rooms,id',
            'extra_pax' => 'nullable|array',
            'check_in' => 'required|date',
            'booking_type' => 'required|in:overnight,short_time',
            'num_nights' => 'nullable|integer|min:1',
            'short_time_hours' => 'nullable|integer|in:3,6,12,24',
            'discount_type' => 'nullable|string',
            'discount_amount' => 'nullable|numeric|min:0',
            'promo_code' => 'nullable|string',
        ]);

        $user = $request->user();
        $discountType = $request->discount_type;
        if (in_array($discountType, ['promo', 'staff', 'complimentary']) && $user->role !== 'admin' && ! $request->filled('promo_code')) {
            return response()->json(['error' => 'Only administrators can apply promo, staff, or complimentary discounts.'], 403);
        }

        $checkInRaw = HotelDateTime::parseLocal($request->check_in);

        $rooms = Room::with('type')->whereIn('id', $request->room_ids)->get();
        $numRooms = count($rooms);

        $roomGuests = [];
        foreach ($rooms as $room) {
            $extraPax = $request->extra_pax[$room->id] ?? 0;
            $roomGuests[$room->id] = max(1, (int) $room->type->max_occupancy) + (int) $extraPax;
        }

        $checkIn = $checkInRaw->format('Y-m-d H:i:s');

        $reqDiscountType = $request->discount_type ?: '';
        $reqDiscountAmountTotal = (float) ($request->discount_amount ?: 0);

        if ($request->filled('promo_code')) {
            $promo = PromoCode::where('code', $request->promo_code)->first();
            if ($promo && $promo->isValid()) {
                $reqDiscountType = 'promo';
                $combinedSubtotal = 0;
                foreach ($rooms as $room) {
                    $t = BookingService::calculateBookingAmounts($room, $request->booking_type, $checkIn, $request->num_nights ?: 1, $request->short_time_hours ?: 3, '', 0, $roomGuests[$room->id]);
                    $combinedSubtotal += $t['base_amount'] + $t['peak_surcharge'];
                }

                if ($promo->discount_type === 'percent') {
                    $reqDiscountAmountTotal = round($combinedSubtotal * ($promo->discount_value / 100), 2);
                } else {
                    $reqDiscountAmountTotal = min($combinedSubtotal, (float) $promo->discount_value);
                }
            } else {
                return response()->json(['error' => 'The promo code is invalid or expired.'], 422);
            }
        }

        $discountPerRoom = in_array($reqDiscountType, ['promo', 'staff']) && $numRooms > 0
            ? round($reqDiscountAmountTotal / $numRooms, 2)
            : 0;

        $totals = [
            'base_amount' => 0,
            'peak_surcharge' => 0,
            'discount_amount' => 0,
            'total_amount' => 0,
            'expected_check_out' => null,
            'is_peak' => false,
            'conflicts' => [],
        ];

        $room_breakdown = [];

        foreach ($rooms as $room) {
            $amounts = BookingService::calculateBookingAmounts(
                $room,
                $request->booking_type,
                $checkIn,
                $request->num_nights ?: 1,
                $request->short_time_hours ?: 3,
                $reqDiscountType,
                $discountPerRoom,
                $roomGuests[$room->id]
            );
            $totals['extra_pax_charges'] = ($totals['extra_pax_charges'] ?? 0) + ($amounts['extra_pax_charges'] ?? 0);

            $totals['base_amount'] += $amounts['base_amount'];
            $totals['peak_surcharge'] += $amounts['peak_surcharge'];
            $totals['discount_amount'] += $amounts['discount_amount'];
            $totals['total_amount'] += $amounts['total_amount'];
            $totals['expected_check_out'] = $amounts['expected_check_out'];
            if ($amounts['is_peak']) {
                $totals['is_peak'] = true;
            }

            $expectedCheckOut = $amounts['expected_check_out'];
            $overlap = Booking::where('room_id', $room->id)
                ->whereIn('status', ['active', 'reserved'])
                ->where('check_in', '<', $expectedCheckOut)
                ->where('expected_check_out', '>', $checkIn)
                ->first();

            if ($overlap) {
                $totals['conflicts'][] = [
                    'room_number' => $room->room_number,
                    'booking_ref' => $overlap->booking_ref,
                    'status' => $overlap->status,
                    'guest_name' => $overlap->guest_name,
                    'check_in' => HotelDateTime::fromStay($overlap->check_in)->format('M d, Y h:i A'),
                    'expected_check_out' => HotelDateTime::fromStay($overlap->expected_check_out)->format('M d, Y h:i A'),
                ];
            }

            $room_breakdown[$room->id] = $amounts;
        }

        if (count($totals['conflicts']) > 0) {
            $totals['conflict'] = $totals['conflicts'][0]; // Fallback for single-room UI compatibility
        }

        if ($request->filled('promo_code')) {
            $totals['promo_code'] = $request->promo_code;
        }

        return response()->json([
            'totals' => $totals,
            'room_breakdown' => $room_breakdown,
        ]);
    }

    public function getAvailableRooms(Request $request)
    {
        $request->validate([
            'check_in' => 'required|date',
            'booking_type' => 'required|in:overnight,short_time',
            'num_nights' => 'nullable|integer|min:1',
            'short_time_hours' => 'nullable|integer|in:3,6,12,24',
            'exclude_booking_id' => 'nullable|exists:bookings,id',
        ]);

        $checkInRaw = HotelDateTime::parseLocal($request->check_in);
        $checkInTime = $checkInRaw;

        $dummyExpectedCheckOut = $request->booking_type === 'overnight'
            ? BookingService::buildOvernightExpectedCheckOut(
                $checkInTime->format('Y-m-d H:i:s'),
                $request->num_nights ?: 1
            )
            : $checkInTime->copy()->addHours($request->short_time_hours ?: 3);

        $checkInStr = $checkInTime->format('Y-m-d H:i:s');
        $checkOutStr = $dummyExpectedCheckOut->format('Y-m-d H:i:s');

        $query = Booking::whereIn('status', ['active', 'reserved'])
            ->where('check_in', '<', $checkOutStr)
            ->where('expected_check_out', '>', $checkInStr);

        if ($request->has('exclude_booking_id') && $request->exclude_booking_id) {
            $query->where('id', '!=', $request->exclude_booking_id);
        }

        $bookedRoomIds = $query->pluck('room_id')->toArray();

        $availableRooms = Room::with('type')
            ->whereNotIn('id', $bookedRoomIds)
            ->orderBy('room_number', 'asc')
            ->get();

        return response()->json([
            'available_rooms' => $availableRooms,
        ]);
    }

    public function store(StoreReservationRequest $request)
    {
        $user = $request->user();

        if ($request->booking_source === 'online'
            && ! in_array($request->payment_method, ['gcash', 'bank_transfer'], true)) {
            return back()->withErrors([
                'payment_method' => 'Online bookings must be paid through GCash or bank transfer.',
            ]);
        }

        $discountType = $request->discount_type;
        if (in_array($discountType, ['promo', 'staff', 'complimentary']) && $user->role !== 'admin' && ! $request->filled('promo_code')) {
            return back()->withErrors(['discount_type' => 'Only administrators can apply promo, staff, or complimentary discounts.']);
        }

        if (! ShiftService::requireActiveShift($user)) {
            return back()->with('error', 'You must have an active shift to process a booking.');
        }

        $idImagePath = null;
        if ($request->hasFile('id_image')) {
            $idImagePath = $request->file('id_image')->store('id_images', 'public');
        }

        try {
            return DB::transaction(function () use ($request, $user, $idImagePath) {
                $rooms = Room::with('type')->whereIn('id', $request->room_ids)->lockForUpdate()->get();
                $numRooms = count($rooms);

                $roomGuests = [];
                foreach ($rooms as $room) {
                    $extraPax = $request->extra_pax[$room->id] ?? 0;
                    $roomGuests[$room->id] = max(1, (int) $room->type->max_occupancy) + (int) $extraPax;
                }
                $checkInRaw = HotelDateTime::parseLocal($request->check_in);
                $checkInTime = $checkInRaw;

                $reqDiscountType = $request->discount_type ?: '';
                $reqDiscountAmountTotal = (float) ($request->discount_amount ?: 0);
                $promoCodeModel = null;

                if ($request->filled('promo_code')) {
                    $promoCodeModel = PromoCode::where('code', $request->promo_code)->lockForUpdate()->first();
                    if ($promoCodeModel && $promoCodeModel->isValid()) {
                        $reqDiscountType = 'promo';
                        $combinedSubtotal = 0;
                        foreach ($rooms as $room) {
                            $t = BookingService::calculateBookingAmounts($room, $request->booking_type, $checkInTime->format('Y-m-d H:i:s'), $request->num_nights ?: 1, $request->short_time_hours ?: 3, '', 0, $roomGuests[$room->id]);
                            $combinedSubtotal += $t['base_amount'] + $t['peak_surcharge'];
                        }

                        if ($promoCodeModel->discount_type === 'percent') {
                            $reqDiscountAmountTotal = round($combinedSubtotal * ($promoCodeModel->discount_value / 100), 2);
                        } else {
                            $reqDiscountAmountTotal = min($combinedSubtotal, (float) $promoCodeModel->discount_value);
                        }
                    } else {
                        throw new \Exception('The promo code is invalid or expired.');
                    }
                }

                $discountPerRoom = in_array($reqDiscountType, ['promo', 'staff']) && $numRooms > 0
                    ? round($reqDiscountAmountTotal / $numRooms, 2)
                    : 0;

                $totalCombinedAmount = 0;
                $roomPricings = [];

                foreach ($rooms as $room) {
                    $pricing = BookingService::calculateBookingAmounts(
                        $room,
                        $request->booking_type,
                        $checkInTime->format('Y-m-d H:i:s'),
                        $request->num_nights ?: 1,
                        $request->short_time_hours ?: 3,
                        $reqDiscountType,
                        $discountPerRoom,
                        $roomGuests[$room->id]
                    );

                    $totalCombinedAmount += $pricing['total_amount'];
                    $roomPricings[$room->id] = $pricing;

                    // Overlap safety check
                    $expectedCheckOut = $pricing['expected_check_out'];
                    $overlap = Booking::where('room_id', $room->id)
                        ->whereIn('status', ['active', 'reserved'])
                        ->where('check_in', '<', $expectedCheckOut)
                        ->where('expected_check_out', '>', $checkInTime->format('Y-m-d H:i:s'))
                        ->first();

                    if ($overlap) {
                        throw new \Exception("Double-booking conflict: Room {$room->room_number} is already booked by {$overlap->guest_name} from {$overlap->check_in} to {$overlap->expected_check_out}.");
                    }
                }

                // Payment verification for combined amount
                $paymentRatio = $request->input('payment_ratio', 'full');
                $collectedAmountTotal = ($paymentRatio === 'half') ? round($totalCombinedAmount / 2, 2) : $totalCombinedAmount;

                $paymentMethod = $request->payment_method;
                $cashAmountTotal = 0.00;
                $gcashAmountTotal = 0.00;
                $cashTendered = null;
                $changeGiven = null;
                $refNum = $request->gcash_ref ?: $request->reference_number ?: null;
                $paymentComponents = [];
                if ($paymentMethod !== 'cash' && blank($refNum)) {
                    throw new \InvalidArgumentException('A payment reference is required for non-cash reservation payments.');
                }

                if ($paymentMethod === 'cash') {
                    $cashTendered = round((float) $request->cash_received, 2);
                    if ($cashTendered + 0.01 < $collectedAmountTotal) {
                        throw new \InvalidArgumentException(
                            'Cash received must be at least the amount due today.'
                        );
                    }
                    $changeGiven = round($cashTendered - $collectedAmountTotal, 2);
                    $cashAmountTotal = $collectedAmountTotal;
                    $paymentComponents[] = [
                        'payment_method_code' => 'cash',
                        'amount' => $collectedAmountTotal,
                    ];
                } elseif ($paymentMethod === 'gcash') {
                    $gcashAmountTotal = $collectedAmountTotal;
                    $paymentComponents[] = [
                        'payment_method_code' => 'gcash',
                        'amount' => $collectedAmountTotal,
                        'reference_number' => $refNum,
                    ];
                } elseif ($paymentMethod === 'split') {
                    $cashAmountTotal = (float) ($request->cash_amount ?: 0);
                    $gcashAmountTotal = (float) ($request->gcash_amount ?: 0);
                    if (abs(($cashAmountTotal + $gcashAmountTotal) - $collectedAmountTotal) > 0.01) {
                        throw new \Exception("Split amounts must equal the collected deposit amount ₱{$collectedAmountTotal}.");
                    }
                    $paymentComponents = [
                        [
                            'payment_method_code' => 'cash',
                            'amount' => $cashAmountTotal,
                        ],
                        [
                            'payment_method_code' => 'gcash',
                            'amount' => $gcashAmountTotal,
                            'reference_number' => $refNum,
                        ],
                    ];
                } else {
                    $paymentComponents[] = [
                        'payment_method_code' => $paymentMethod,
                        'amount' => $collectedAmountTotal,
                        'reference_number' => $refNum,
                    ];
                }

                // Guest profile
                $guestProfile = GuestProfile::firstOrCreate(
                    ['full_name' => trim($request->guest_name)],
                    [
                        'contact_number' => $request->guest_contact,
                        'id_type' => $request->guest_id_type,
                        'id_number' => $request->guest_id_number,
                        'id_image_path' => $idImagePath,
                        'email' => $request->guest_email,
                        'address' => $request->guest_address,
                    ]
                );

                if (! $guestProfile->wasRecentlyCreated) {
                    $guestProfile->update([
                        'contact_number' => $request->guest_contact ?: $guestProfile->contact_number,
                        'id_type' => $request->guest_id_type ?: $guestProfile->id_type,
                        'id_number' => $request->guest_id_number ?: $guestProfile->id_number,
                        'id_image_path' => $idImagePath ?: $guestProfile->id_image_path,
                        'email' => $request->guest_email ?: $guestProfile->email,
                        'address' => $request->guest_address ?: $guestProfile->address,
                    ]);
                }

                // Pending digital payments are not counted until verification.
                if ($paymentMethod === 'cash') {
                    $guestProfile->total_spent += $collectedAmountTotal;
                }
                $guestProfile->save();

                // Create Bookings
                $groupRef = $numRooms > 1 ? 'GRP-'.strtoupper(Str::random(4)).$checkInTime->format('ymdHi') : null;
                $createdBookingIds = [];
                $paymentAllocations = [];

                // Split the deposit evenly
                $amountPaidPerRoom = round($collectedAmountTotal / $numRooms, 2);
                $remainingAllocation = $collectedAmountTotal;
                $roomPosition = 0;

                foreach ($rooms as $room) {
                    $roomPosition++;
                    $pricing = $roomPricings[$room->id];
                    $bookingRef = 'RES-'.strtoupper(Str::random(4)).$checkInTime->format('ymdHis').$room->id;
                    $allocatedAmount = $roomPosition === $numRooms
                        ? round($remainingAllocation, 2)
                        : $amountPaidPerRoom;
                    $remainingAllocation = round($remainingAllocation - $allocatedAmount, 2);
                    $legacyPaymentMethod = in_array($paymentMethod, ['cash', 'gcash', 'card', 'bank_transfer', 'split'], true)
                        ? $paymentMethod
                        : 'bank_transfer';

                    $booking = Booking::create([
                        'booking_ref' => $bookingRef,
                        'group_ref' => $groupRef,
                        'room_id' => $room->id,
                        'guest_profile_id' => $guestProfile->id,
                        'booked_by_user_id' => $user->id,
                        'guest_name' => $guestProfile->full_name,
                        'booker_name' => $request->booker_name ?: $guestProfile->full_name,
                        'guest_contact' => $guestProfile->contact_number,
                        'booker_contact' => $request->booker_contact ?: $guestProfile->contact_number,
                        'guest_id_type' => $guestProfile->id_type,
                        'guest_id_number' => $guestProfile->id_number,
                        'guest_id_image_path' => $idImagePath ?: $guestProfile->id_image_path,
                        'num_guests' => $roomGuests[$room->id],
                        'booking_type' => $request->booking_type,
                        'booking_source' => $request->booking_source,
                        'short_time_hours' => $request->booking_type !== 'overnight' ? $request->short_time_hours : null,
                        'num_nights' => $request->booking_type === 'overnight' ? $request->num_nights : null,
                        'check_in' => HotelDateTime::toDatabase($checkInTime),
                        'expected_check_out' => $pricing['expected_check_out'],
                        'status' => 'reserved',
                        'payment_status' => 'unpaid',
                        'base_amount' => $pricing['base_amount'],
                        'peak_surcharge' => $pricing['peak_surcharge'],
                        'extra_pax_charges' => $pricing['extra_pax_charges'] ?? 0,
                        'discount_type' => $reqDiscountType ?: null,
                        'discount_amount' => $pricing['discount_amount'],
                        'total_amount' => $pricing['total_amount'],
                        'amount_paid' => 0,
                        'payment_method' => $legacyPaymentMethod,
                        'cash_amount' => 0,
                        'gcash_amount' => 0,
                        'gcash_ref' => null,
                        'is_peak' => $pricing['is_peak'],
                        'notes' => $request->notes ? trim($request->notes.($request->filled('promo_code') ? "\nApplied Promo: ".$request->promo_code : '').($paymentRatio === 'half' ? "\nPartial 50% deposit paid." : '')) : ($request->filled('promo_code') ? 'Applied Promo: '.$request->promo_code : ($paymentRatio === 'half' ? 'Partial 50% deposit paid.' : null)),
                        'checked_in_by' => $user->id,
                    ]);

                    $createdBookingIds[] = $booking->id;
                    $paymentAllocations[$booking->id] = $allocatedAmount;
                }

                $paymentStatus = $paymentMethod === 'cash' ? 'verified' : 'pending';
                $payment = app(PaymentService::class)->record([
                    'payer_name' => $request->booker_name ?: $guestProfile->full_name,
                    'payer_contact' => $request->booker_contact ?: $guestProfile->contact_number,
                    'payment_method_code' => $paymentMethod,
                    'reference_number' => $refNum,
                    'amount' => $collectedAmountTotal,
                    'cash_tendered' => $cashTendered,
                    'change_given' => $changeGiven,
                    'payment_type' => $paymentRatio === 'full' ? 'full' : 'deposit',
                    'status' => $paymentStatus,
                    'recorded_by' => $user->id,
                    'remarks' => $request->transaction_notes,
                ], $paymentAllocations, $paymentComponents, [
                    'transaction_type' => 'check_in',
                    'description' => 'Initial reservation payment for '.($groupRef ?: $createdBookingIds[0]),
                ]);

                if ($promoCodeModel) {
                    $promoCodeModel->increment('used_count');
                }

                $roomNumbers = $rooms->pluck('room_number')->join(', ');
                $msg = $numRooms > 1
                    ? "Group Reservation {$groupRef} registered for Rooms: {$roomNumbers}."
                    : "Reservation registered for Room {$roomNumbers}.";

                BookingService::auditLog(
                    $user->id,
                    'BOOKING_RESERVATION',
                    'bookings',
                    $createdBookingIds[0],
                    null,
                    $groupRef ?? 'SINGLE',
                    $msg." Payment {$payment->receipt_number} recorded as {$paymentStatus}: ₱{$collectedAmountTotal} via {$paymentMethod}."
                );

                return redirect()->route('reservations.index')->with('success', $msg);
            });
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function checkin(Booking $booking, Request $request)
    {
        $user = $request->user();

        if (! ShiftService::requireActiveShift($user)) {
            return back()->with('error', 'You must have an active shift to perform check-in.');
        }

        try {
            $checkedInBooking = DB::transaction(function () use ($booking, $user) {
                $lockedBooking = Booking::lockForUpdate()->findOrFail($booking->id);
                if ($lockedBooking->paymentAllocations()->exists()) {
                    app(PaymentService::class)->syncBooking($lockedBooking);
                    $lockedBooking->refresh();
                }

                $room = Room::lockForUpdate()->findOrFail($lockedBooking->room_id);
                $this->activatePaidReservation($lockedBooking, $room, $user);

                return $lockedBooking;
            });

            return redirect()->route('rooms.index')->with(
                'success',
                "Guest {$checkedInBooking->guest_name} checked in successfully from reservation {$checkedInBooking->booking_ref}!"
            );
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function settleAndCheckin(Booking $booking, Request $request, PaymentService $payments)
    {
        $validated = $request->validate([
            'payer_name' => 'required|string|max:150',
            'payer_contact' => 'nullable|string|max:50',
            'payment_method_code' => 'required|in:cash,gcash,bank_transfer,card,maya,other_ewallet,other,split',
            'reference_number' => 'nullable|string|max:100',
            'remarks' => 'nullable|string|max:1000',
            'components' => 'nullable|array',
            'components.*.payment_method_code' => 'required_with:components|in:cash,gcash,bank_transfer,card,maya,other_ewallet,other',
            'components.*.amount' => 'required_with:components|numeric|min:0.01',
            'components.*.reference_number' => 'nullable|string|max:100',
        ]);

        $user = $request->user();
        $activeShift = ShiftSession::where('user_id', $user->id)
            ->whereNull('ended_at')
            ->first();
        if (! $activeShift) {
            return back()->with('error', 'Start your shift before collecting an arrival balance.');
        }

        $method = $validated['payment_method_code'];
        $reference = trim((string) ($validated['reference_number'] ?? ''));
        if ($method !== 'cash' && $reference === '') {
            return back()->withErrors([
                'reference_number' => 'A payment reference is required for non-cash payments.',
            ]);
        }

        try {
            $result = DB::transaction(function () use ($booking, $validated, $method, $reference, $user, $activeShift, $payments) {
                $lockedBooking = Booking::lockForUpdate()->findOrFail($booking->id);
                if ($lockedBooking->status !== 'reserved') {
                    throw new \RuntimeException('Only reserved bookings can be settled and checked in.');
                }

                if ($lockedBooking->paymentAllocations()->exists()) {
                    $payments->syncBooking($lockedBooking);
                    $lockedBooking->refresh();
                }

                $pendingAmount = (float) $lockedBooking->paymentAllocations()
                    ->whereHas('payment', fn ($q) => $q->where('status', 'pending'))
                    ->sum('allocated_amount');
                if ($pendingAmount > 0) {
                    throw new \RuntimeException(
                        'This reservation already has a pending payment of ₱'
                        .number_format($pendingAmount, 2)
                        .'. Verify or reject it before recording another payment.'
                    );
                }

                $balance = round(max(0, (float) $lockedBooking->total_amount - (float) $lockedBooking->amount_paid), 2);
                if ($balance <= 0.01) {
                    $room = Room::lockForUpdate()->findOrFail($lockedBooking->room_id);
                    $this->activatePaidReservation($lockedBooking, $room, $user);

                    return ['booking' => $lockedBooking, 'payment' => null, 'checked_in' => true];
                }

                $components = $validated['components'] ?? [];
                if ($method === 'split') {
                    $componentTotal = round((float) collect($components)->sum('amount'), 2);
                    if (count($components) < 2 || abs($componentTotal - $balance) > 0.01) {
                        throw new \InvalidArgumentException(
                            'Split payment components must equal the exact outstanding balance of ₱'
                            .number_format($balance, 2).'.'
                        );
                    }
                } else {
                    $components = [];
                }

                $status = $method === 'cash' ? 'verified' : 'pending';
                $payment = $payments->record([
                    'payer_name' => $validated['payer_name'],
                    'payer_contact' => $validated['payer_contact'] ?? null,
                    'payment_method_code' => $method,
                    'reference_number' => $reference ?: null,
                    'amount' => $balance,
                    'payment_type' => 'final',
                    'status' => $status,
                    'recorded_by' => $user->id,
                    'received_at' => now(),
                    'shift_id' => $activeShift->id,
                    'remarks' => $validated['remarks'] ?? "Arrival balance for {$lockedBooking->booking_ref}",
                ], [$lockedBooking->id => $balance], $components, [
                    'transaction_type' => 'adjustment',
                    'description' => "Arrival balance settlement for {$lockedBooking->booking_ref}",
                ]);

                if ($payment->status === 'verified') {
                    $lockedBooking->refresh();
                    $room = Room::lockForUpdate()->findOrFail($lockedBooking->room_id);
                    $this->activatePaidReservation($lockedBooking, $room, $user);

                    return ['booking' => $lockedBooking, 'payment' => $payment, 'checked_in' => true];
                }

                return ['booking' => $lockedBooking, 'payment' => $payment, 'checked_in' => false];
            });

            if ($result['checked_in']) {
                return redirect()->route('rooms.index')->with(
                    'success',
                    "Balance settled and {$result['booking']->guest_name} checked in successfully."
                );
            }

            return redirect()->route('reservations.index')->with(
                'warning',
                "Payment {$result['payment']->receipt_number} is pending verification. Verify it in Front Desk Payments, then check in the guest."
            );
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Booking $booking, Request $request)
    {
        $request->validate([
            'reason' => 'required|string|max:255',
            'payment_disposition' => 'required|in:retain,full_refund,partial_refund',
            'refund_amount' => 'required_if:payment_disposition,partial_refund|nullable|numeric|min:0.01',
        ]);

        $user = $request->user();

        if ($booking->status !== 'reserved') {
            return back()->with('error', 'Only reserved bookings can be cancelled.');
        }

        return DB::transaction(function () use ($booking, $request, $user) {
            $disposition = $request->payment_disposition;
            $requestedRefund = $disposition === 'full_refund'
                ? (float) $booking->amount_paid
                : ($disposition === 'partial_refund' ? (float) $request->refund_amount : 0.0);
            if ($requestedRefund > (float) $booking->amount_paid + 0.01) {
                throw new \InvalidArgumentException('Refund cannot exceed the verified amount paid.');
            }

            $booking->status = 'cancelled';
            $booking->notes = trim($booking->notes."\nReservation Cancelled. Reason: ".$request->reason.'. Payment disposition: '.$disposition.'.');
            $booking->save();

            $refundReceipts = [];
            $remainingRefund = round($requestedRefund, 2);
            if ($remainingRefund > 0) {
                $allocations = $booking->paymentAllocations()
                    ->with('payment')
                    ->whereHas('payment', fn ($q) => $q
                        ->where('status', 'verified')
                        ->whereNotIn('payment_type', ['refund', 'reversal']))
                    ->orderByDesc('id')
                    ->get();

                foreach ($allocations as $allocation) {
                    if ($remainingRefund <= 0) {
                        break;
                    }

                    $alreadyRefunded = (float) DB::table('payments as p')
                        ->join('payment_allocations as pa', 'pa.payment_id', '=', 'p.id')
                        ->where('p.original_payment_id', $allocation->payment_id)
                        ->where('p.status', 'verified')
                        ->whereIn('p.payment_type', ['refund', 'reversal'])
                        ->where('pa.booking_id', $booking->id)
                        ->sum('pa.allocated_amount');
                    $available = round(max(0, (float) $allocation->allocated_amount - $alreadyRefunded), 2);
                    if ($available <= 0) {
                        continue;
                    }

                    $refund = app(PaymentService::class)->refund(
                        $allocation->payment,
                        min($remainingRefund, $available),
                        $user->id,
                        "Cancellation of {$booking->booking_ref}: {$request->reason}",
                        null,
                        null,
                        $booking->id
                    );
                    $refundReceipts[] = $refund->receipt_number." ({$refund->status})";
                    $remainingRefund = round($remainingRefund - (float) $refund->amount, 2);
                }

                if ($remainingRefund > 0.01) {
                    throw new \RuntimeException('Some historical payments are not yet in the ledger. Backfill and verify them before refunding this full amount.');
                }
            }

            // Note: Room was not occupied, so no room status changes are needed.

            // Revert promo code use count if applicable
            if ($booking->discount_type === 'promo') {
                // Find matching promo code if stored in notes or description
                $promoCode = null;
                if (preg_match('/Applied Promo:\s*([A-Z0-9_-]+)/i', $booking->notes, $matches)) {
                    $promoCode = trim($matches[1]);
                }
                if ($promoCode) {
                    $promoModel = PromoCode::where('code', $promoCode)->first();
                    if ($promoModel) {
                        $promoModel->decrement('used_count');
                    }
                }
            }

            // Create refund/adjustment log transaction
            Transaction::create([
                'booking_id' => $booking->id,
                'transaction_type' => 'adjustment',
                'description' => "Reservation cancelled. Ref: {$booking->booking_ref}. Reason: {$request->reason}",
                'amount' => 0.00,
                'payment_method' => 'na',
                'processed_by' => $user->id,
            ]);

            BookingService::auditLog(
                $user->id,
                'BOOKING_RESERVATION_CANCEL',
                'bookings',
                $booking->id,
                'reserved',
                'cancelled',
                "Cancelled future reservation {$booking->booking_ref} for Room {$booking->room->room_number}. Reason: {$request->reason}. Disposition: {$disposition}. Refunds: ".(implode(', ', $refundReceipts) ?: 'none')
            );

            return redirect()->route('reservations.index')->with(
                'success',
                "Reservation {$booking->booking_ref} cancelled. Payment disposition: {$disposition}."
            );
        });
    }

    public function noshow(Booking $booking, Request $request)
    {
        $user = $request->user();

        if ($booking->status !== 'reserved') {
            return back()->with('error', 'Only pending reservations can be marked as No Show.');
        }

        return DB::transaction(function () use ($booking, $user) {
            $booking->status = 'no_show';
            $booking->notes = trim($booking->notes."\nReservation marked as No Show on ".now()->format('Y-m-d H:i:s').'. Payment disposition: retained; no payment records changed.');
            $booking->save();

            // Create adjustment/log transaction
            Transaction::create([
                'booking_id' => $booking->id,
                'transaction_type' => 'adjustment',
                'description' => "Reservation marked as No-Show. Ref: {$booking->booking_ref}.",
                'amount' => 0.00,
                'payment_method' => 'na',
                'processed_by' => $user->id,
            ]);

            BookingService::auditLog(
                $user->id,
                'BOOKING_RESERVATION_NOSHOW',
                'bookings',
                $booking->id,
                'reserved',
                'no_show',
                "Marked reservation {$booking->booking_ref} as No-Show. Existing payments retained unchanged."
            );

            return redirect()->route('reservations.index')->with('success', "Reservation {$booking->booking_ref} marked as No Show.");
        });
    }

    public function reschedule(Booking $booking, Request $request)
    {
        $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'check_in' => 'required|date',
            'booking_type' => 'required|in:overnight,short_time',
            'num_nights' => 'nullable|integer|min:1',
            'short_time_hours' => 'nullable|integer|in:3,6,12,24',
        ]);

        $user = $request->user();

        if ($booking->status !== 'reserved') {
            return back()->with('error', 'Only pending reservations can be rescheduled.');
        }

        $room = Room::findOrFail($request->room_id);

        return DB::transaction(function () use ($booking, $request, $room, $user) {
            $checkInRaw = HotelDateTime::parseLocal($request->check_in);
            $checkInTime = $checkInRaw;

            // Calculate precise amounts
            $pricing = BookingService::calculateBookingAmounts(
                $room,
                $request->booking_type,
                $checkInTime->format('Y-m-d H:i:s'),
                $request->num_nights ?: 1,
                $request->short_time_hours ?: 3,
                $booking->discount_type ?: '',
                $booking->discount_amount ?: 0
            );

            // Double-booking check excluding this booking
            $expectedCheckOut = $pricing['expected_check_out'];
            $overlap = Booking::where('room_id', $room->id)
                ->where('id', '!=', $booking->id)
                ->whereIn('status', ['active', 'reserved'])
                ->where('check_in', '<', $expectedCheckOut)
                ->where('expected_check_out', '>', $checkInTime->format('Y-m-d H:i:s'))
                ->first();

            if ($overlap) {
                return back()->with('error', "Double-booking conflict: Room is already booked by {$overlap->guest_name} from {$overlap->check_in} to {$overlap->expected_check_out}.");
            }

            $oldBooking = $booking->toArray();

            // Update Booking details
            $booking->room_id = $room->id;
            $booking->check_in = HotelDateTime::toDatabase($checkInTime);
            $booking->expected_check_out = $pricing['expected_check_out'];
            $booking->booking_type = $request->booking_type;
            $booking->short_time_hours = $request->booking_type !== 'overnight' ? $request->short_time_hours : null;
            $booking->num_nights = $request->booking_type === 'overnight' ? $request->num_nights : null;

            $booking->base_amount = $pricing['base_amount'];
            $booking->peak_surcharge = $pricing['peak_surcharge'];
            $booking->discount_amount = $pricing['discount_amount'];
            $booking->total_amount = $pricing['total_amount'];
            $booking->is_peak = $pricing['is_peak'];

            // Adjust payment status
            $booking->payment_status = ($booking->amount_paid >= $pricing['total_amount']) ? 'paid' : 'partial';
            $booking->notes = trim($booking->notes."\nRescheduled on ".now()->format('Y-m-d H:i:s').' to '.$booking->check_in.' in Room '.$room->room_number);
            $booking->save();

            // Transaction log for the adjustment
            Transaction::create([
                'booking_id' => $booking->id,
                'transaction_type' => 'adjustment',
                'description' => "Reservation rescheduled. New date: {$booking->check_in}. New Room: {$room->room_number}.",
                'amount' => 0.00,
                'payment_method' => 'na',
                'processed_by' => $user->id,
            ]);

            BookingService::auditLog(
                $user->id,
                'BOOKING_RESERVATION_RESCHEDULE',
                'bookings',
                $booking->id,
                $oldBooking,
                $booking->toArray(),
                "Rescheduled reservation {$booking->booking_ref} to Room {$room->room_number} on {$booking->check_in}."
            );

            return redirect()->route('reservations.index')->with('success', "Reservation {$booking->booking_ref} rescheduled successfully to Room {$room->room_number}!");
        });
    }

    public function groupCheckin($groupRef, Request $request)
    {
        $user = $request->user();

        if (! ShiftService::requireActiveShift($user)) {
            return back()->with('error', 'You must have an active shift to perform check-in.');
        }

        $bookings = Booking::with('room')->where('group_ref', $groupRef)->where('status', 'reserved')->get();
        if ($bookings->isEmpty()) {
            return back()->with('error', 'No pending reservations found for this group.');
        }

        foreach ($bookings as $b) {
            if ($b->amount_paid < $b->total_amount) {
                return back()->with('error', "Cannot check in group. Room {$b->room->room_number} has a remaining balance. Edit the booking to settle full payment first.");
            }
            if ($b->room->status !== 'vacant') {
                return back()->with('error', "Cannot check in group. Room {$b->room->room_number} is currently {$b->room->status}.");
            }
        }

        return DB::transaction(function () use ($bookings, $user, $groupRef) {
            $stayNow = HotelDateTime::now();
            $roomNumbers = [];

            foreach ($bookings as $booking) {
                $room = $booking->room;
                $room->status = 'occupied';
                $room->save();

                $booking->status = 'active';
                $booking->check_in = HotelDateTime::toDatabase($stayNow);
                $booking->checked_in_by = $user->id;

                $pricing = BookingService::calculateBookingAmounts(
                    $room,
                    $booking->booking_type,
                    HotelDateTime::toDatabase($stayNow),
                    $booking->booking_type === 'overnight' ? max(1, Carbon::parse($booking->check_in)->diffInDays(Carbon::parse($booking->expected_check_out))) : 1,
                    $booking->short_time_hours ?: 3,
                    $booking->discount_type ?: '',
                    $booking->discount_amount
                );
                $booking->expected_check_out = $pricing['expected_check_out'];
                $booking->base_amount = $pricing['base_amount'];
                $booking->peak_surcharge = $pricing['peak_surcharge'];
                $booking->total_amount = $pricing['total_amount'];
                $booking->payment_status = ($booking->amount_paid >= $pricing['total_amount'] * 0.99) ? 'paid' : 'partial';
                $booking->save();

                $roomNumbers[] = $room->room_number;
            }

            $firstBooking = $bookings->first();
            if ($firstBooking->guestProfile) {
                $firstBooking->guestProfile->total_stays += 1;
                $firstBooking->guestProfile->last_visit = $stayNow->toDateString();
                $firstBooking->guestProfile->save();
            }

            BookingService::auditLog(
                $user->id,
                'GROUP_CHECK_IN',
                'bookings',
                null,
                'reserved',
                'active',
                "Group {$groupRef} checked in for rooms: ".implode(', ', $roomNumbers)
            );

            return redirect()->route('rooms.index')->with('success', 'Group checked in successfully for rooms: '.implode(', ', $roomNumbers));
        });
    }

    public function groupCheckout($groupRef, Request $request)
    {
        $request->validate([
            'waive_late_fee' => 'nullable|boolean',
        ]);

        $user = $request->user();

        $activeShift = ShiftSession::where('user_id', $user->id)
            ->whereNull('ended_at')
            ->first();

        if (! $activeShift && $user->role !== 'admin') {
            return back()->with('error', 'You must have an active shift to perform check-out.');
        }

        $waiveLateFee = (bool) ($request->waive_late_fee ?? false);

        $bookings = Booking::with('room')->where('group_ref', $groupRef)->where('status', 'active')->get();
        if ($bookings->isEmpty()) {
            return back()->with('error', 'No active reservations found for this group.');
        }

        foreach ($bookings as $b) {
            $lateHours = BookingService::calculateLateCheckoutHours($b->expected_check_out);
            $lateFee = $waiveLateFee ? 0.00 : BookingService::calculateLateCheckoutFee($b->expected_check_out);
            $inventoryTotal = InventoryUsage::where('booking_id', $b->id)->sum('total_price');
            $balance = ($b->total_amount + $lateFee + $b->extension_fee + $inventoryTotal) - $b->amount_paid;

            if ($balance > 0) {
                return back()->with('error', "Cannot perform group check-out. Room {$b->room->room_number} has an outstanding balance of ₱".number_format($balance, 2).'. Please check out that room individually.');
            }
        }

        return DB::transaction(function () use ($bookings, $user, $groupRef, $waiveLateFee) {
            $roomNumbers = [];

            foreach ($bookings as $booking) {
                $room = $booking->room;
                $room->status = 'cleaning';
                $room->save();

                $lateHours = BookingService::calculateLateCheckoutHours($booking->expected_check_out);
                $originalLateFee = BookingService::calculateLateCheckoutFee($booking->expected_check_out);
                $lateFee = $waiveLateFee ? 0.00 : $originalLateFee;

                $booking->status = 'checked_out';
                $booking->check_out = HotelDateTime::toDatabase();
                $booking->checked_out_by = $user->id;
                $booking->late_hours = $lateHours;
                $booking->late_checkout_fee = $lateFee;
                if ($waiveLateFee && $originalLateFee > 0) {
                    $booking->notes = trim(($booking->notes ? $booking->notes."\n" : '').'Late check-out fee of ₱'.number_format($originalLateFee, 2).' waived by '.$user->name.'.');
                }
                $booking->save();

                // Create transaction trace for bookkeeping and shift log compliance
                Transaction::create([
                    'booking_id' => $booking->id,
                    'transaction_type' => 'check_out',
                    'description' => "Group check-out completed for Room {$room->room_number}. Ref: {$booking->booking_ref}".($waiveLateFee && $originalLateFee > 0 ? ' (Late fee of ₱'.number_format($originalLateFee, 2).' waived)' : ''),
                    'amount' => 0.00,
                    'payment_method' => 'na',
                    'cash_amount' => 0.00,
                    'gcash_amount' => 0.00,
                    'processed_by' => $user->id,
                ]);

                $roomNumbers[] = $room->room_number;
            }

            BookingService::auditLog(
                $user->id,
                'GROUP_CHECK_OUT',
                'bookings',
                null,
                'active',
                'checked_out',
                "Group {$groupRef} checked out for rooms: ".implode(', ', $roomNumbers)
            );

            return redirect()->route('rooms.index')->with('success', 'Group checked out successfully. Rooms sent to housekeeping: '.implode(', ', $roomNumbers));
        });
    }

    public function groupCheckoutSettle($groupRef, Request $request)
    {
        $request->validate([
            'waive_late_fee' => 'nullable|boolean',
            'payment_method' => 'required|in:cash,gcash,bank_transfer,card,maya,other_ewallet,other,split',
            'cash_amount' => 'nullable|numeric|min:0',
            'gcash_amount' => 'nullable|numeric|min:0',
            'gcash_ref' => 'nullable|string|max:50',
            'bank_amount' => 'nullable|numeric|min:0',
            'bank_ref' => 'nullable|string|max:50',
            'transaction_notes' => 'nullable|string',
        ]);

        $user = $request->user();

        $activeShift = ShiftSession::where('user_id', $user->id)
            ->whereNull('ended_at')
            ->first();

        if (! $activeShift && $user->role !== 'admin') {
            return back()->with('error', 'You must have an active shift to perform check-out.');
        }

        $waiveLateFee = (bool) ($request->waive_late_fee ?? false);

        $bookings = Booking::with('room')->where('group_ref', $groupRef)->where('status', 'active')->get();
        if ($bookings->isEmpty()) {
            return back()->with('error', 'No active stays found for this group.');
        }

        // Calculate individual balances and group balances
        $roomsData = [];
        $groupBalanceTotal = 0.00;

        foreach ($bookings as $b) {
            $lateHours = BookingService::calculateLateCheckoutHours($b->expected_check_out);
            $lateFee = $waiveLateFee ? 0.00 : BookingService::calculateLateCheckoutFee($b->expected_check_out);
            $inventoryTotal = (float) InventoryUsage::where('booking_id', $b->id)->sum('total_price');

            $roomTotal = (float) $b->total_amount + $lateFee + $inventoryTotal;
            $balance = $roomTotal - (float) $b->amount_paid;

            $roomsData[$b->id] = [
                'booking' => $b,
                'late_hours' => $lateHours,
                'late_fee' => $lateFee,
                'inventory_total' => $inventoryTotal,
                'room_total' => $roomTotal,
                'balance' => max(0.00, $balance),
            ];

            $groupBalanceTotal += max(0.00, $balance);
        }

        // If balance > 0, validate the payment sum
        if ($groupBalanceTotal > 0) {
            $payCash = (float) ($request->cash_amount ?? 0.00);
            $payGcash = (float) ($request->gcash_amount ?? 0.00);
            $payBank = (float) ($request->bank_amount ?? 0.00);

            $method = $request->payment_method;
            if ($method === 'cash') {
                $payTotal = $payCash;
            } elseif ($method === 'gcash') {
                $payTotal = $payGcash;
            } elseif (in_array($method, ['bank_transfer', 'card', 'maya', 'other_ewallet', 'other'], true)) {
                $payTotal = $payBank;
            } else { // split
                $payTotal = $payCash + $payGcash + $payBank;
            }

            if (round($payTotal, 2) < round($groupBalanceTotal, 2)) {
                return back()->with('error', 'Insufficient payment amount. Balance is ₱'.number_format($groupBalanceTotal, 2).', payment is ₱'.number_format($payTotal, 2));
            }
        } else {
            $payCash = 0.00;
            $payGcash = 0.00;
            $payBank = 0.00;
        }

        return DB::transaction(function () use ($bookings, $user, $groupRef, $waiveLateFee, $roomsData, $request, $payCash, $payGcash, $payBank) {
            $now = now();
            $roomNumbers = [];

            // We will distribute the payments proportionally or simply cover the balance of each room
            $remainingCash = $payCash;
            $remainingGcash = $payGcash;
            $remainingBank = $payBank;
            $paymentAllocations = [];

            foreach ($bookings as $booking) {
                $room = $booking->room;
                $room->status = 'cleaning';
                $room->save();

                $bData = $roomsData[$booking->id];
                $balance = $bData['balance'];

                $roomCash = 0.00;
                $roomGcash = 0.00;
                $roomBank = 0.00;

                if ($balance > 0) {
                    // Pull cash share
                    $roomCash = min($balance, $remainingCash);
                    $balance -= $roomCash;
                    $remainingCash -= $roomCash;

                    // Pull gcash share
                    if ($balance > 0) {
                        $roomGcash = min($balance, $remainingGcash);
                        $balance -= $roomGcash;
                        $remainingGcash -= $roomGcash;
                    }

                    // Pull bank share
                    if ($balance > 0) {
                        $roomBank = min($balance, $remainingBank);
                        $balance -= $roomBank;
                        $remainingBank -= $roomBank;
                    }
                }

                $paidShare = $roomCash + $roomGcash + $roomBank;

                // Update booking
                $booking->status = 'checked_out';
                $booking->check_out = HotelDateTime::toDatabase();
                $booking->checked_out_by = $user->id;
                $booking->late_hours = $bData['late_hours'];
                $booking->late_checkout_fee = $bData['late_fee'];
                $booking->total_amount = $bData['room_total'];

                $originalLateFee = BookingService::calculateLateCheckoutFee($booking->expected_check_out);
                if ($waiveLateFee && $originalLateFee > 0) {
                    $booking->notes = trim(($booking->notes ? $booking->notes."\n" : '').'Late check-out fee of ₱'.number_format($originalLateFee, 2).' waived by '.$user->name.'.');
                }
                $booking->save();

                if ($paidShare > 0) {
                    $paymentAllocations[$booking->id] = round($paidShare, 2);
                }

                $roomNumbers[] = $room->room_number;
            }

            $payment = null;
            if ($paymentAllocations) {
                $actualCash = round($payCash - $remainingCash, 2);
                $actualGcash = round($payGcash - $remainingGcash, 2);
                $actualBank = round($payBank - $remainingBank, 2);
                $actualTotal = round(array_sum($paymentAllocations), 2);
                $method = $request->payment_method;
                $reference = $request->gcash_ref ?: $request->bank_ref;

                if ($method === 'split') {
                    $components = array_values(array_filter([
                        $actualCash > 0 ? ['payment_method_code' => 'cash', 'amount' => $actualCash] : null,
                        $actualGcash > 0 ? ['payment_method_code' => 'gcash', 'amount' => $actualGcash, 'reference_number' => $request->gcash_ref] : null,
                        $actualBank > 0 ? ['payment_method_code' => 'bank_transfer', 'amount' => $actualBank, 'reference_number' => $request->bank_ref] : null,
                    ]));
                } else {
                    $components = [[
                        'payment_method_code' => $method,
                        'amount' => $actualTotal,
                        'reference_number' => $method === 'cash' ? null : $reference,
                    ]];
                }

                $firstBooking = $bookings->first();
                $payment = app(PaymentService::class)->record([
                    'payer_name' => $firstBooking->booker_name ?: $firstBooking->guest_name,
                    'payer_contact' => $firstBooking->booker_contact ?: $firstBooking->guest_contact,
                    'payment_method_code' => $method,
                    'reference_number' => $reference,
                    'amount' => $actualTotal,
                    'payment_type' => 'final',
                    'status' => 'verified',
                    'recorded_by' => $user->id,
                    'verified_by' => $user->id,
                    'verified_at' => $now,
                    'remarks' => $request->transaction_notes,
                ], $paymentAllocations, $components, [
                    'transaction_type' => 'check_out',
                    'description' => "Group checkout settlement for {$groupRef}",
                ]);

                foreach ($payment->allocations as $allocation) {
                    $transactionId = Transaction::where('payment_id', $payment->id)
                        ->where('booking_id', $allocation->booking_id)
                        ->value('id');
                    if ($transactionId) {
                        InventoryUsage::where('booking_id', $allocation->booking_id)
                            ->whereNull('transaction_id')
                            ->update(['transaction_id' => $transactionId]);
                    }
                }
            }

            BookingService::auditLog(
                $user->id,
                'GROUP_CHECK_OUT',
                'bookings',
                null,
                'active',
                'checked_out',
                "Group {$groupRef} checkout settled for rooms: ".implode(', ', $roomNumbers).'. Total Settle Payment: ₱'.number_format($payCash + $payGcash + $payBank, 2)
            );

            return redirect()->route('rooms.index')->with('success', 'Group checkout settled successfully. Rooms sent to housekeeping: '.implode(', ', $roomNumbers));
        });
    }

    public function groupCheckoutPreview($groupRef, Request $request)
    {
        $bookings = Booking::with(['room', 'room.type'])
            ->where('group_ref', $groupRef)
            ->where('status', 'active')
            ->get();

        if ($bookings->isEmpty()) {
            return response()->json(['error' => 'No active stays found for this group.'], 404);
        }

        $now = HotelDateTime::now();
        $rooms = [];
        $totalBase = 0.00;
        $totalLate = 0.00;
        $totalMinibar = 0.00;
        $totalExtension = 0.00;
        $totalPaid = 0.00;
        $totalDue = 0.00;

        foreach ($bookings as $b) {
            $lateHours = BookingService::calculateLateCheckoutHours($b->expected_check_out, $now);
            $lateFee = BookingService::calculateLateCheckoutFee($b->expected_check_out, $now);
            $inventoryTotal = (float) InventoryUsage::where('booking_id', $b->id)->sum('total_price');

            $roomTotal = (float) $b->total_amount + $lateFee + $inventoryTotal;
            $balance = max(0.00, $roomTotal - (float) $b->amount_paid);

            $rooms[] = [
                'id' => $b->id,
                'booking_ref' => $b->booking_ref,
                'room_number' => $b->room ? $b->room->room_number : '?',
                'guest_name' => $b->guest_name,
                'check_in' => $b->check_in ? HotelDateTime::toDatabase($b->check_in) : null,
                'expected_check_out' => $b->expected_check_out ? HotelDateTime::toDatabase($b->expected_check_out) : null,
                'base_amount' => (float) $b->base_amount + (float) $b->peak_surcharge + (float) $b->extra_pax_charges - (float) $b->discount_amount,
                'extension_fee' => (float) $b->extension_fee,
                'late_hours' => $lateHours,
                'late_fee' => $lateFee,
                'inventory_total' => $inventoryTotal,
                'total_amount' => $roomTotal,
                'amount_paid' => (float) $b->amount_paid,
                'balance' => $balance,
            ];

            $totalBase += ((float) $b->base_amount + (float) $b->peak_surcharge + (float) $b->extra_pax_charges - (float) $b->discount_amount);
            $totalLate += $lateFee;
            $totalMinibar += $inventoryTotal;
            $totalExtension += (float) $b->extension_fee;
            $totalPaid += (float) $b->amount_paid;
            $totalDue += $balance;
        }

        return response()->json([
            'group_ref' => $groupRef,
            'rooms' => $rooms,
            'totals' => [
                'base' => $totalBase,
                'late' => $totalLate,
                'minibar' => $totalMinibar,
                'extension' => $totalExtension,
                'paid' => $totalPaid,
                'balance' => $totalDue,
            ],
        ]);
    }

    private function activatePaidReservation(Booking $booking, Room $room, $user): void
    {
        if ($booking->status !== 'reserved') {
            throw new \RuntimeException('Only reserved bookings can be checked in.');
        }

        $balance = round(max(0, (float) $booking->total_amount - (float) $booking->amount_paid), 2);
        if ($balance > 0.01) {
            throw new \RuntimeException(
                'Cannot check in. Remaining verified balance of ₱'
                .number_format($balance, 2)
                .' must be settled first.'
            );
        }

        if ($room->status !== 'vacant') {
            throw new \RuntimeException("Cannot check in. Room {$room->room_number} is currently {$room->status}.");
        }

        $now = HotelDateTime::now();
        $expectedCheckOut = $booking->booking_type === 'overnight'
            ? BookingService::buildOvernightExpectedCheckOut(
                HotelDateTime::toDatabase($now),
                max(1, (int) ($booking->num_nights ?: 1))
            )
            : BookingService::buildShortTimeExpectedCheckOut(
                HotelDateTime::toDatabase($now),
                max(1, (int) ($booking->short_time_hours ?: 3))
            );

        $room->status = 'occupied';
        $room->save();

        // Preserve the confirmed booking price and payment history. Arrival changes
        // the operational stay timestamps only; it must not create a new charge.
        $booking->status = 'active';
        $booking->check_in = HotelDateTime::toDatabase($now);
        $booking->expected_check_out = $expectedCheckOut->format('Y-m-d H:i:s');
        $booking->payment_status = 'paid';
        $booking->checked_in_by = $user->id;
        $booking->save();

        if ($booking->guestProfile) {
            $booking->guestProfile->increment('total_stays');
            $booking->guestProfile->last_visit = $now->toDateString();
            $booking->guestProfile->save();
        }

        BookingService::auditLog(
            $user->id,
            'CHECK_IN_FROM_RESERVATION',
            'bookings',
            $booking->id,
            'reserved',
            'active',
            "Checked in guest {$booking->guest_name} from reservation {$booking->booking_ref} into Room {$room->room_number}. Confirmed booking price preserved at ₱".number_format((float) $booking->total_amount, 2).'.'
        );
    }
}
