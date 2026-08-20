<?php

namespace App\Services;

use App\Exceptions\AmenityIssuanceException;
use App\Models\Booking;
use App\Models\InventoryAmenityIssue;
use App\Models\InventoryAmenityIssueItem;
use App\Models\InventoryItem;
use App\Models\InventoryStockMovement;
use App\Models\Setting;
use App\Models\StayAmenityPolicy;
use App\Models\User;
use App\Support\HotelDateTime;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AmenityIssuanceService
{
    public function __construct(
        private readonly InventoryChangeRequestService $movements
    ) {}

    public function groupedActivePolicies(): array
    {
        $grouped = [
            StayAmenityPolicy::STAY_OVERNIGHT => [],
            StayAmenityPolicy::STAY_SHORT_TIME_24 => [],
        ];

        $rows = StayAmenityPolicy::query()
            ->where('is_active', true)
            ->with(['item' => fn ($query) => $query->withTrashed()])
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            if (! $row->item || ! $row->item->is_active || $row->item->trashed()) {
                continue;
            }

            $grouped[$row->stay_key][] = $this->policyPayload($row);
        }

        return $grouped;
    }

    public function activePoliciesForStay(?Booking $booking): Collection
    {
        $stayKey = StayAmenityPolicy::stayKeyFor($booking);
        if ($stayKey === null) {
            return collect();
        }

        return StayAmenityPolicy::query()
            ->where('stay_key', $stayKey)
            ->where('is_active', true)
            ->with(['item' => fn ($query) => $query->withTrashed()])
            ->orderBy('id')
            ->get()
            ->filter(fn (StayAmenityPolicy $policy) => $policy->item && $policy->item->is_active && ! $policy->item->trashed())
            ->values();
    }

    public function stayPayload(Booking $booking): array
    {
        $policies = $this->activePoliciesForStay($booking);
        $issues = InventoryAmenityIssue::query()
            ->with(['items.item', 'issuer', 'room', 'shiftSession'])
            ->where('booking_id', $booking->id)
            ->orderBy('issued_at')
            ->orderBy('id')
            ->get();

        $initialIssuedItemIds = InventoryAmenityIssueItem::query()
            ->whereNotNull('initial_claim_key')
            ->where('initial_claim_key', 'like', $booking->id.':%')
            ->pluck('inventory_item_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        return [
            'stay_key' => StayAmenityPolicy::stayKeyFor($booking),
            'stay_key_label' => StayAmenityPolicy::stayKeyFor($booking)
                ? StayAmenityPolicy::stayKeyLabel(StayAmenityPolicy::stayKeyFor($booking))
                : null,
            'policies' => $policies->map(fn (StayAmenityPolicy $policy) => $this->policyPayload($policy))->values()->all(),
            'initial_issued_item_ids' => $initialIssuedItemIds,
            'issues' => $issues->map(fn (InventoryAmenityIssue $issue) => $this->issuePayload($issue))->values()->all(),
        ];
    }

    public function issueAfterCheckIn(User $user, Booking $booking, array $lines): ?InventoryAmenityIssue
    {
        $normalized = $this->normalizeLines($lines);
        if ($normalized === []) {
            return null;
        }

        $itemIds = collect($normalized)->pluck('inventory_item_id')->sort()->implode('-');
        $idempotencyKey = 'checkin:'.$booking->id.':initial:'.$itemIds;

        return $this->issue(
            $user,
            $booking,
            $normalized,
            InventoryAmenityIssue::CONTEXT_INITIAL,
            'Issued at check-in',
            $idempotencyKey
        );
    }

    /**
     * @param  list<array{inventory_item_id: int, quantity: int}>  $lines
     */
    public function issue(
        User $user,
        Booking $booking,
        array $lines,
        string $context,
        ?string $notes = null,
        ?string $idempotencyKey = null
    ): InventoryAmenityIssue {
        ShiftService::assertCanChangeTrackedInventory(
            $user,
            'An active Front Desk register is required to issue complimentary inventory.'
        );
        app(InventoryTurnoverService::class)->assertItemsMutable(
            $user,
            collect($lines)->pluck('inventory_item_id')->all()
        );

        if (! in_array($context, [InventoryAmenityIssue::CONTEXT_INITIAL, InventoryAmenityIssue::CONTEXT_REFILL], true)) {
            throw new AmenityIssuanceException('Issue context must be initial or refill.');
        }

        if ($booking->status !== 'active') {
            throw new AmenityIssuanceException('Complimentary amenities can only be issued to an active stay.');
        }

        $stayKey = StayAmenityPolicy::stayKeyFor($booking);
        if ($stayKey === null) {
            throw new AmenityIssuanceException('This stay type is not eligible for complimentary amenities.');
        }

        $normalized = $this->normalizeLines($lines);
        if ($normalized === []) {
            throw new AmenityIssuanceException('Select at least one amenity to issue.');
        }

        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);

        try {
            return DB::transaction(function () use ($user, $booking, $normalized, $context, $notes, $idempotencyKey, $stayKey) {
                $lockedBooking = Booking::query()
                    ->whereKey($booking->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($idempotencyKey) {
                    $existing = InventoryAmenityIssue::query()
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();
                    if ($existing) {
                        return $existing->load(['items.item', 'issuer', 'room']);
                    }
                }

                $itemIds = collect($normalized)->pluck('inventory_item_id')->sort()->values()->all();
                $lockedItems = InventoryItem::query()
                    ->whereIn('id', $itemIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $policies = StayAmenityPolicy::query()
                    ->where('stay_key', $stayKey)
                    ->where('is_active', true)
                    ->whereIn('inventory_item_id', $itemIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('inventory_item_id');

                foreach ($normalized as $line) {
                    $item = $lockedItems->get($line['inventory_item_id']);
                    if (! $item || ! $item->is_active) {
                        throw new AmenityIssuanceException('An amenity product is missing or inactive.');
                    }

                    $policy = $policies->get($item->id);
                    if (! $policy) {
                        throw new AmenityIssuanceException(
                            $item->item_name.' is not configured as a complimentary amenity for this stay type.'
                        );
                    }

                    if ($context === InventoryAmenityIssue::CONTEXT_INITIAL) {
                        $claimKey = InventoryAmenityIssueItem::claimKey($lockedBooking->id, $item->id);
                        $alreadyIssued = InventoryAmenityIssueItem::query()
                            ->where('initial_claim_key', $claimKey)
                            ->lockForUpdate()
                            ->exists();
                        if ($alreadyIssued) {
                            throw new AmenityIssuanceException(
                                "Initial {$item->item_name} has already been issued for this stay. Use Refill."
                            );
                        }
                    }

                    if ((int) $item->current_stock < $line['quantity']) {
                        throw new AmenityIssuanceException(
                            "{$item->item_name} is out of stock. The guest remains checked in. You may issue it later from Stay Details."
                        );
                    }
                }

                $lockedBooking->loadMissing('room');

                $header = InventoryAmenityIssue::create([
                    'reference' => $this->nextReference(),
                    'booking_id' => $lockedBooking->id,
                    'room_id' => $lockedBooking->room_id,
                    'shift_session_id' => ShiftService::activeRegisterId(),
                    'issued_by' => $user->id,
                    'issued_at' => now(),
                    'issue_context' => $context,
                    'idempotency_key' => $idempotencyKey,
                    'notes' => $notes,
                ]);

                $contextLabel = $context === InventoryAmenityIssue::CONTEXT_INITIAL ? 'Initial' : 'Refill';
                $roomNumber = $lockedBooking->room?->room_number ?? $lockedBooking->room_id;

                foreach ($normalized as $line) {
                    $item = $lockedItems->get($line['inventory_item_id']);
                    $quantity = $line['quantity'];
                    $stockBefore = (int) $item->current_stock;
                    $stockAfter = $stockBefore - $quantity;
                    if ($stockAfter < 0) {
                        throw new AmenityIssuanceException(
                            "{$item->item_name} is out of stock. The guest remains checked in. You may issue it later from Stay Details."
                        );
                    }

                    $item->current_stock = $stockAfter;
                    $item->save();

                    $movement = $this->movements->recordExternalMovement(
                        $item,
                        InventoryStockMovement::TYPE_COMPLIMENTARY_AMENITY,
                        -1 * $quantity,
                        $stockBefore,
                        $stockAfter,
                        $user->id,
                        InventoryAmenityIssue::SOURCE_TYPE,
                        $header->id,
                        "{$header->reference} {$contextLabel} complimentary amenity for Room {$roomNumber} ({$lockedBooking->booking_ref})."
                    );

                    InventoryAmenityIssueItem::create([
                        'amenity_issue_id' => $header->id,
                        'inventory_item_id' => $item->id,
                        'quantity' => $quantity,
                        'stock_movement_id' => $movement->id,
                        'initial_claim_key' => $context === InventoryAmenityIssue::CONTEXT_INITIAL
                            ? InventoryAmenityIssueItem::claimKey($lockedBooking->id, $item->id)
                            : null,
                    ]);
                }

                BookingService::auditLog(
                    $user->id,
                    'INVENTORY_AMENITY_ISSUE',
                    'inventory_amenity_issues',
                    $header->id,
                    null,
                    $header->reference,
                    "Issued {$contextLabel} complimentary amenities {$header->reference} for {$lockedBooking->booking_ref}."
                );

                return $header->load(['items.item', 'issuer', 'room']);
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($idempotencyKey) {
                $existing = InventoryAmenityIssue::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    return $existing->load(['items.item', 'issuer', 'room']);
                }
            }

            $message = $e->getMessage();
            if (str_contains($message, 'initial_claim_key') || str_contains($message, 'iai_items_initial_claim_unique')) {
                throw new AmenityIssuanceException(
                    'Initial amenities have already been issued for this stay. Use Refill.'
                );
            }

            throw new AmenityIssuanceException('Complimentary issuance could not be completed. Please try again.');
        }
    }

    private function nextReference(): string
    {
        $date = HotelDateTime::now()->format('Ymd');
        $key = 'amenity_issue_seq_'.$date;
        $head = 'INV-A-'.$date.'-';

        $setting = Setting::query()->where('key', $key)->lockForUpdate()->first();
        $sequence = $setting ? (int) $setting->value : 1;
        if ($sequence < 1) {
            $sequence = 1;
        }

        do {
            $reference = $head.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
            $exists = InventoryAmenityIssue::query()->where('reference', $reference)->exists();
            $sequence++;
        } while ($exists);

        if ($setting) {
            $setting->value = (string) $sequence;
            $setting->save();
        } else {
            Setting::create([
                'key' => $key,
                'value' => (string) $sequence,
            ]);
        }

        return $reference;
    }

    /**
     * @param  list<array{inventory_item_id?: mixed, quantity?: mixed}>  $lines
     * @return list<array{inventory_item_id: int, quantity: int}>
     */
    private function normalizeLines(array $lines): array
    {
        $merged = [];
        foreach ($lines as $line) {
            $itemId = (int) ($line['inventory_item_id'] ?? 0);
            $quantity = (int) ($line['quantity'] ?? 0);
            if ($itemId < 1 || $quantity < 1) {
                continue;
            }
            $merged[$itemId] = ($merged[$itemId] ?? 0) + $quantity;
        }

        ksort($merged);

        $normalized = [];
        foreach ($merged as $itemId => $quantity) {
            $normalized[] = [
                'inventory_item_id' => (int) $itemId,
                'quantity' => (int) $quantity,
            ];
        }

        return $normalized;
    }

    private function normalizeIdempotencyKey(?string $key): ?string
    {
        $key = trim((string) $key);

        return $key === '' ? null : mb_substr($key, 0, 100);
    }

    private function policyPayload(StayAmenityPolicy $policy): array
    {
        return [
            'id' => $policy->id,
            'stay_key' => $policy->stay_key,
            'inventory_item_id' => $policy->inventory_item_id,
            'default_quantity' => (int) $policy->default_quantity,
            'is_active' => (bool) $policy->is_active,
            'item_name' => $policy->item?->item_name,
            'unit' => $policy->item?->unit,
            'current_stock' => (int) ($policy->item?->current_stock ?? 0),
        ];
    }

    private function issuePayload(InventoryAmenityIssue $issue): array
    {
        $issuedAt = $issue->issued_at instanceof Carbon
            ? $issue->issued_at
            : Carbon::parse($issue->issued_at);

        return [
            'id' => $issue->id,
            'reference' => $issue->reference,
            'issue_context' => $issue->issue_context,
            'issue_context_label' => $issue->issue_context === InventoryAmenityIssue::CONTEXT_INITIAL ? 'Initial' : 'Refill',
            'issued_at' => $issuedAt?->toIso8601String(),
            'issued_at_manila' => HotelDateTime::formatUtcForDisplay($issuedAt),
            'issued_at_display' => $issuedAt
                ? $issuedAt->copy()->timezone(HotelDateTime::TIMEZONE)->format('M j, Y • g:i:s A')
                : null,
            'shift_session_id' => $issue->shift_session_id,
            'register_label' => $issue->shift_session_id ? 'Shift #'.$issue->shift_session_id : 'No register',
            'issued_by' => $issue->issued_by,
            'issued_by_name' => $issue->issuer?->full_name,
            'room_id' => $issue->room_id,
            'room_number' => $issue->room?->room_number,
            'notes' => $issue->notes,
            'items' => $issue->items->map(fn (InventoryAmenityIssueItem $line) => [
                'inventory_item_id' => $line->inventory_item_id,
                'item_name' => $line->item?->item_name,
                'quantity' => (int) $line->quantity,
                'stock_movement_id' => $line->stock_movement_id,
            ])->values()->all(),
        ];
    }
}
