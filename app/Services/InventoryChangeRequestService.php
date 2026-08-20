<?php

namespace App\Services;

use App\Exceptions\InventoryChangeRequestException;
use App\Models\InventoryChangeRequest;
use App\Models\InventoryItem;
use App\Models\InventoryStockMovement;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class InventoryChangeRequestService
{
    public const CATEGORIES = ['minibar', 'toiletries', 'laundry', 'amenities', 'supplies'];

    public const REQUEST_IMAGE_DIRECTORY = 'inventory/requests';

    public function submitCreateItemRequest(User $user, array $data, ?UploadedFile $image = null): InventoryChangeRequest
    {
        $data['item_name'] = InventoryItem::displayName((string) ($data['item_name'] ?? ''));
        $this->assertUniqueItemName($data['item_name'], $data['category']);

        $pendingImagePath = null;
        if ($image) {
            $pendingImagePath = $image->store(self::REQUEST_IMAGE_DIRECTORY, 'public');
        }

        try {
            $request = InventoryChangeRequest::create([
                'request_type' => InventoryChangeRequest::TYPE_CREATE_ITEM,
                'inventory_item_id' => null,
                'request_payload' => [
                    'item_name' => $data['item_name'],
                    'category' => $data['category'],
                    'unit' => $data['unit'],
                    'current_stock' => (int) $data['current_stock'],
                    'minimum_stock' => (int) $data['minimum_stock'],
                    'unit_cost' => $data['unit_cost'],
                    'selling_price' => $data['selling_price'],
                ],
                'pending_image_path' => $pendingImagePath,
                'quantity' => (int) $data['current_stock'],
                'stock_at_request' => null,
                'status' => InventoryChangeRequest::STATUS_PENDING,
                'reason' => $data['reason'] ?? 'New inventory item request',
                'requested_by' => $user->id,
            ]);
        } catch (Throwable $e) {
            if ($pendingImagePath) {
                Storage::disk('public')->delete($pendingImagePath);
            }

            throw $e;
        }

        BookingService::auditLog(
            $user->id,
            'INVENTORY_REQUESTED',
            'inventory_change_requests',
            $request->id,
            null,
            [
                'request_id' => $request->id,
                'request_type' => $request->request_type,
                'requester_id' => $user->id,
                'payload' => $request->request_payload,
            ],
            $request->reason
        );

        $this->forgetNotificationCache($user->id);

        return $request;
    }

    public function submitAdjustmentRequest(User $user, InventoryItem $item, string $type, int $quantity, string $reason): InventoryChangeRequest
    {
        app(InventoryTurnoverService::class)->assertItemsMutable($user, [$item->id]);
        $this->assertAdjustmentQuantity($type, $quantity);

        $request = InventoryChangeRequest::create([
            'request_type' => $type,
            'inventory_item_id' => $item->id,
            'request_payload' => [
                'item_name' => $item->item_name,
                'adjustment_type' => $type,
            ],
            'quantity' => $quantity,
            'stock_at_request' => (int) $item->current_stock,
            'status' => InventoryChangeRequest::STATUS_PENDING,
            'reason' => $reason,
            'requested_by' => $user->id,
        ]);

        BookingService::auditLog(
            $user->id,
            'INVENTORY_REQUESTED',
            'inventory_change_requests',
            $request->id,
            [
                'inventory_item_id' => $item->id,
                'stock_at_request' => $request->stock_at_request,
            ],
            [
                'request_id' => $request->id,
                'request_type' => $type,
                'quantity' => $quantity,
                'requester_id' => $user->id,
            ],
            $reason
        );

        $this->forgetNotificationCache($user->id);

        return $request;
    }

    public function createItemImmediately(User $admin, array $data, ?UploadedFile $image = null): InventoryItem
    {
        $data['item_name'] = InventoryItem::displayName((string) ($data['item_name'] ?? ''));
        $this->assertUniqueItemName($data['item_name'], $data['category']);

        $storedImagePath = null;
        if ($image) {
            $storedImagePath = $image->store('inventory', 'public');
        }

        try {
            return DB::transaction(function () use ($admin, $data, $storedImagePath) {
                $this->assertUniqueItemName($data['item_name'], $data['category']);

                $item = InventoryItem::create([
                    'item_name' => $data['item_name'],
                    'category' => $data['category'],
                    'unit' => $data['unit'],
                    'current_stock' => (int) $data['current_stock'],
                    'minimum_stock' => (int) $data['minimum_stock'],
                    'unit_cost' => $data['unit_cost'],
                    'selling_price' => $data['selling_price'],
                    'image_path' => $this->publicImagePath($storedImagePath),
                    'is_turnover_tracked' => (bool) ($data['is_turnover_tracked'] ?? false),
                ]);

                $changeRequest = InventoryChangeRequest::create([
                    'request_type' => InventoryChangeRequest::TYPE_CREATE_ITEM,
                    'inventory_item_id' => $item->id,
                    'request_payload' => [
                        'item_name' => $item->item_name,
                        'category' => $item->category,
                        'unit' => $item->unit,
                        'current_stock' => $item->current_stock,
                        'minimum_stock' => $item->minimum_stock,
                        'unit_cost' => $item->unit_cost,
                        'selling_price' => $item->selling_price,
                    ],
                    'quantity' => (int) $item->current_stock,
                    'stock_at_request' => 0,
                    'status' => InventoryChangeRequest::STATUS_APPROVED,
                    'reason' => 'Admin created inventory item',
                    'requested_by' => $admin->id,
                    'reviewed_by' => $admin->id,
                    'reviewed_at' => now(),
                ]);

                $this->recordMovement(
                    $item,
                    InventoryStockMovement::TYPE_INITIAL_STOCK,
                    (int) $item->current_stock,
                    0,
                    (int) $item->current_stock,
                    $admin->id,
                    $changeRequest->id,
                    'inventory_item',
                    $item->id,
                    "Initial stock for {$item->item_name}."
                );

                BookingService::auditLog(
                    $admin->id,
                    'INVENTORY_CREATE',
                    'inventory_items',
                    $item->id,
                    null,
                    $item->item_name,
                    "Created new inventory item {$item->item_name} with initial stock {$item->current_stock}."
                );

                BookingService::auditLog(
                    $admin->id,
                    'INVENTORY_STOCK_MOVEMENT',
                    'inventory_stock_movements',
                    $item->id,
                    0,
                    $item->current_stock,
                    "Initial stock {$item->current_stock} for {$item->item_name}."
                );

                return $item;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($storedImagePath) {
                Storage::disk('public')->delete($storedImagePath);
            }

            throw $this->duplicateItemException();
        } catch (Throwable $e) {
            if ($storedImagePath) {
                Storage::disk('public')->delete($storedImagePath);
            }

            throw $e;
        }
    }

    public function adjustItemImmediately(User $admin, InventoryItem $item, string $type, int $quantity, string $reason): InventoryItem
    {
        app(InventoryTurnoverService::class)->assertItemsMutable($admin, [$item->id], InventoryTurnoverService::CONTEXT_ADMIN_ADJUST);
        $this->assertAdjustmentQuantity($type, $quantity);

        return DB::transaction(function () use ($admin, $item, $type, $quantity, $reason) {
            $locked = InventoryItem::query()->whereKey($item->id)->lockForUpdate()->first();
            if (! $locked) {
                throw new InventoryChangeRequestException('The inventory item no longer exists.');
            }

            $before = (int) $locked->current_stock;
            $after = $this->projectedStock($type, $before, $quantity);

            if ($type === InventoryChangeRequest::TYPE_SUBTRACT && $before < $quantity) {
                throw new InventoryChangeRequestException("Insufficient stock. Current: {$before}, attempted reduction: {$quantity}");
            }

            $locked->current_stock = $after;
            $locked->save();

            $changeRequest = InventoryChangeRequest::create([
                'request_type' => $type,
                'inventory_item_id' => $locked->id,
                'request_payload' => [
                    'item_name' => $locked->item_name,
                    'adjustment_type' => $type,
                ],
                'quantity' => $quantity,
                'stock_at_request' => $before,
                'status' => InventoryChangeRequest::STATUS_APPROVED,
                'reason' => $reason,
                'requested_by' => $admin->id,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            $movementType = InventoryStockMovement::typeForAdjustment($type);

            $this->recordMovement(
                $locked,
                $movementType,
                $after - $before,
                $before,
                $after,
                $admin->id,
                $changeRequest->id,
                'inventory_change_request',
                $changeRequest->id,
                $reason
            );

            BookingService::auditLog(
                $admin->id,
                'STOCK_ADJUSTMENT',
                'inventory_items',
                $locked->id,
                $before,
                $after,
                "Stock adjusted ({$type} to {$quantity}). Reason: {$reason}"
            );

            BookingService::auditLog(
                $admin->id,
                'INVENTORY_STOCK_MOVEMENT',
                'inventory_stock_movements',
                $locked->id,
                $before,
                $after,
                $reason
            );

            return $locked->fresh();
        });
    }

    public function updateCatalogItem(InventoryItem $item, array $data, ?UploadedFile $image = null): InventoryItem
    {
        $data['item_name'] = InventoryItem::displayName((string) ($data['item_name'] ?? $item->item_name));
        $this->assertUniqueItemName($data['item_name'], $data['category'], $item->id);

        $newStoredPath = null;
        $oldStoredPath = $image ? $this->storedDiskPath($item->image_path) : null;

        if ($image) {
            $newStoredPath = $image->store('inventory', 'public');
            $data['image_path'] = $this->publicImagePath($newStoredPath);
        }

        try {
            DB::transaction(function () use ($item, $data) {
                $item->update($data);
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($newStoredPath) {
                Storage::disk('public')->delete($newStoredPath);
            }

            throw $this->duplicateItemException();
        } catch (Throwable $e) {
            if ($newStoredPath) {
                Storage::disk('public')->delete($newStoredPath);
            }

            throw $e;
        }

        if ($oldStoredPath && $oldStoredPath !== $newStoredPath) {
            $this->deleteStoredImageAfterCommit($oldStoredPath, $item->id);
        }

        return $item->fresh();
    }

    public function approve(User $admin, InventoryChangeRequest $changeRequest, ?string $reviewNote = null): InventoryChangeRequest
    {
        $reviewNote = $reviewNote !== null ? trim($reviewNote) : null;
        if ($reviewNote === '') {
            $reviewNote = null;
        }

        return DB::transaction(function () use ($admin, $changeRequest, $reviewNote) {
            $lockedRequest = InventoryChangeRequest::query()
                ->whereKey($changeRequest->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedRequest || ! $lockedRequest->isPending()) {
                throw new InventoryChangeRequestException('Only pending requests can be approved.');
            }

            if ($lockedRequest->isCreateItem()) {
                $this->approveCreateItem($admin, $lockedRequest, $reviewNote);
            } else {
                $this->approveAdjustment($admin, $lockedRequest, $reviewNote);
            }

            $this->forgetNotificationCache($lockedRequest->requested_by);

            return $lockedRequest->fresh(['item', 'requester', 'reviewer']);
        });
    }

    public function reject(User $admin, InventoryChangeRequest $changeRequest, string $reviewNote): InventoryChangeRequest
    {
        $reviewNote = trim($reviewNote);
        if ($reviewNote === '') {
            throw new InventoryChangeRequestException('A rejection reason is required.');
        }

        $pendingImagePath = null;
        $requestId = null;

        $rejected = DB::transaction(function () use ($admin, $changeRequest, $reviewNote, &$pendingImagePath, &$requestId) {
            $lockedRequest = InventoryChangeRequest::query()
                ->whereKey($changeRequest->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedRequest || ! $lockedRequest->isPending()) {
                throw new InventoryChangeRequestException('Only pending requests can be rejected.');
            }

            $pendingImagePath = $lockedRequest->pending_image_path;
            $requestId = $lockedRequest->id;

            $lockedRequest->update([
                'status' => InventoryChangeRequest::STATUS_REJECTED,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'review_note' => $reviewNote,
            ]);

            BookingService::auditLog(
                $admin->id,
                'INVENTORY_REQUEST_REJECTED',
                'inventory_change_requests',
                $lockedRequest->id,
                [
                    'request_id' => $lockedRequest->id,
                    'inventory_item_id' => $lockedRequest->inventory_item_id,
                    'requester_id' => $lockedRequest->requested_by,
                    'request_data' => $lockedRequest->request_payload,
                    'reason' => $lockedRequest->reason,
                ],
                [
                    'reviewer_id' => $admin->id,
                    'review_note' => $reviewNote,
                ],
                $reviewNote
            );

            $this->forgetNotificationCache($lockedRequest->requested_by);

            return $lockedRequest;
        });

        $this->scheduleRejectedImageCleanup($requestId, $pendingImagePath);

        return $rejected->fresh(['item', 'requester', 'reviewer']);
    }

    public function recordExternalMovement(
        InventoryItem $item,
        string $movementType,
        int $quantityChange,
        int $stockBefore,
        int $stockAfter,
        ?int $performedBy,
        ?string $sourceType,
        ?int $sourceId,
        ?string $notes = null
    ): InventoryStockMovement {
        $movement = $this->recordMovement(
            $item,
            $movementType,
            $quantityChange,
            $stockBefore,
            $stockAfter,
            $performedBy,
            null,
            $sourceType,
            $sourceId,
            $notes
        );

        BookingService::auditLog(
            $performedBy,
            'INVENTORY_STOCK_MOVEMENT',
            'inventory_stock_movements',
            $item->id,
            $stockBefore,
            $stockAfter,
            $notes
        );

        return $movement;
    }

    public function forgetNotificationCache(?int $requesterId = null): void
    {
        Cache::forget('notifications.role_admin');
        if ($requesterId) {
            Cache::forget('notifications.user_'.$requesterId);
        }
    }

    private function approveCreateItem(User $admin, InventoryChangeRequest $request, ?string $reviewNote): void
    {
        $payload = $request->request_payload ?? [];
        $name = InventoryItem::displayName((string) ($payload['item_name'] ?? ''));
        $category = $payload['category'] ?? '';

        $this->assertUniqueItemName($name, $category);

        $storedImagePath = $request->pending_image_path;
        $imagePath = null;
        if ($storedImagePath && Storage::disk('public')->exists($storedImagePath)) {
            $imagePath = $this->publicImagePath($storedImagePath);
        }

        try {
            $item = InventoryItem::create([
                'item_name' => $name,
                'category' => $category,
                'unit' => $payload['unit'] ?? 'piece',
                'current_stock' => (int) ($payload['current_stock'] ?? $request->quantity),
                'minimum_stock' => (int) ($payload['minimum_stock'] ?? 0),
                'unit_cost' => $payload['unit_cost'] ?? 0,
                'selling_price' => $payload['selling_price'] ?? 0,
                'image_path' => $imagePath,
                'is_turnover_tracked' => (bool) ($payload['is_turnover_tracked'] ?? false),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            throw $this->duplicateItemException();
        }

        $request->update([
            'inventory_item_id' => $item->id,
            'status' => InventoryChangeRequest::STATUS_APPROVED,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'review_note' => $reviewNote,
            'pending_image_path' => null,
        ]);

        $this->recordMovement(
            $item,
            InventoryStockMovement::TYPE_INITIAL_STOCK,
            (int) $item->current_stock,
            0,
            (int) $item->current_stock,
            $admin->id,
            $request->id,
            'inventory_change_request',
            $request->id,
            $request->reason
        );

        BookingService::auditLog(
            $admin->id,
            'INVENTORY_REQUEST_APPROVED',
            'inventory_change_requests',
            $request->id,
            [
                'request_id' => $request->id,
                'requester_id' => $request->requested_by,
                'request_data' => $payload,
            ],
            [
                'inventory_item_id' => $item->id,
                'reviewer_id' => $admin->id,
                'stock_before' => 0,
                'stock_after' => $item->current_stock,
                'review_note' => $reviewNote,
            ],
            $request->reason
        );

        BookingService::auditLog(
            $admin->id,
            'INVENTORY_STOCK_MOVEMENT',
            'inventory_stock_movements',
            $item->id,
            0,
            $item->current_stock,
            $request->reason
        );
    }

    private function approveAdjustment(User $admin, InventoryChangeRequest $request, ?string $reviewNote): void
    {
        if (! $request->inventory_item_id) {
            throw new InventoryChangeRequestException('This stock request is missing its inventory item and cannot be approved.');
        }

        $item = InventoryItem::query()
            ->whereKey($request->inventory_item_id)
            ->lockForUpdate()
            ->first();

        if (! $item) {
            throw new InventoryChangeRequestException('The inventory item no longer exists and this request cannot be approved.');
        }

        $current = (int) $item->current_stock;
        $quantity = (int) $request->quantity;
        $type = $request->request_type;

        if ($type === InventoryChangeRequest::TYPE_SET && $request->stock_at_request !== null && $current !== (int) $request->stock_at_request) {
            throw new InventoryChangeRequestException(
                'Current stock changed after this set-exact request was submitted. Reject it and ask for a new physical-count entry.'
            );
        }

        if ($type === InventoryChangeRequest::TYPE_SUBTRACT && $current < $quantity) {
            throw new InventoryChangeRequestException(
                "Insufficient stock to approve this subtraction. Current: {$current}, requested: {$quantity}."
            );
        }

        app(InventoryTurnoverService::class)->assertItemsMutable($admin, [$item->id], InventoryTurnoverService::CONTEXT_ADMIN_ADJUST);

        $after = $this->projectedStock($type, $current, $quantity);
        $item->current_stock = $after;
        $item->save();

        $request->update([
            'status' => InventoryChangeRequest::STATUS_APPROVED,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'review_note' => $reviewNote,
        ]);

        $movementType = InventoryStockMovement::typeForAdjustment($type);

        $this->recordMovement(
            $item,
            $movementType,
            $after - $current,
            $current,
            $after,
            $admin->id,
            $request->id,
            'inventory_change_request',
            $request->id,
            $request->reason
        );

        BookingService::auditLog(
            $admin->id,
            'INVENTORY_REQUEST_APPROVED',
            'inventory_change_requests',
            $request->id,
            [
                'request_id' => $request->id,
                'inventory_item_id' => $item->id,
                'requester_id' => $request->requested_by,
                'stock_at_request' => $request->stock_at_request,
                'reason' => $request->reason,
            ],
            [
                'reviewer_id' => $admin->id,
                'stock_before' => $current,
                'stock_after' => $after,
                'review_note' => $reviewNote,
            ],
            $request->reason
        );

        BookingService::auditLog(
            $admin->id,
            'INVENTORY_STOCK_MOVEMENT',
            'inventory_stock_movements',
            $item->id,
            $current,
            $after,
            $request->reason
        );
    }

    public function projectedStock(string $type, int $current, int $quantity): int
    {
        return match ($type) {
            InventoryChangeRequest::TYPE_ADD => $current + $quantity,
            InventoryChangeRequest::TYPE_SUBTRACT => $current - $quantity,
            InventoryChangeRequest::TYPE_SET, InventoryChangeRequest::TYPE_CREATE_ITEM => $quantity,
            default => $current,
        };
    }

    public function assertUniqueItemName(string $name, string $category, ?int $exceptId = null): void
    {
        $query = InventoryItem::query()
            ->where('category', $category)
            ->where('normalized_name', InventoryItem::normalizeName($name));

        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }

        if ($query->exists()) {
            throw $this->duplicateItemException();
        }
    }

    private function assertAdjustmentQuantity(string $type, int $quantity): void
    {
        if (! in_array($type, [InventoryChangeRequest::TYPE_ADD, InventoryChangeRequest::TYPE_SUBTRACT, InventoryChangeRequest::TYPE_SET], true)) {
            throw new InventoryChangeRequestException('Invalid adjustment type.');
        }

        if (in_array($type, [InventoryChangeRequest::TYPE_ADD, InventoryChangeRequest::TYPE_SUBTRACT], true) && $quantity < 1) {
            throw new InventoryChangeRequestException('Add and subtract quantities must be at least 1.');
        }

        if ($type === InventoryChangeRequest::TYPE_SET && $quantity < 0) {
            throw new InventoryChangeRequestException('Set-exact quantity cannot be negative.');
        }
    }

    private function recordMovement(
        InventoryItem $item,
        string $movementType,
        int $quantityChange,
        int $stockBefore,
        int $stockAfter,
        ?int $performedBy,
        ?int $changeRequestId,
        ?string $sourceType,
        ?int $sourceId,
        ?string $notes,
        ?int $shiftSessionIdOverride = null
    ): InventoryStockMovement {
        return InventoryStockMovement::record([
            'inventory_item_id' => $item->id,
            'inventory_change_request_id' => $changeRequestId,
            'movement_type' => $movementType,
            'quantity_change' => $quantityChange,
            'stock_before' => $stockBefore,
            'stock_after' => $stockAfter,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'performed_by' => $performedBy,
            'shift_session_id' => $shiftSessionIdOverride ?? app(InventoryTurnoverService::class)->movementShiftIdForNewRow(),
            'notes' => $notes,
        ]);
    }

    private function publicImagePath(?string $storedPath): ?string
    {
        if (! $storedPath) {
            return null;
        }

        if (str_starts_with($storedPath, '/storage/')) {
            return $storedPath;
        }

        return '/storage/'.$storedPath;
    }

    private function storedDiskPath(?string $publicPath): ?string
    {
        if (! $publicPath) {
            return null;
        }

        return ltrim(str_replace('/storage/', '', $publicPath), '/');
    }

    private function deleteStoredImageAfterCommit(string $path, int $itemId): void
    {
        $ran = false;
        $cleanup = function () use ($path, $itemId, &$ran) {
            if ($ran) {
                return;
            }
            $ran = true;

            try {
                Storage::disk('public')->delete($path);
            } catch (Throwable $e) {
                Log::warning('Failed to delete replaced inventory catalog image', [
                    'inventory_item_id' => $itemId,
                    'path' => $path,
                    'exception' => $e->getMessage(),
                ]);
            }
        };

        DB::afterCommit($cleanup);

        if (app()->runningUnitTests() && DB::transactionLevel() > 0) {
            $cleanup();
        }
    }

    private function scheduleRejectedImageCleanup(?int $requestId, ?string $path): void
    {
        if (! $requestId || ! $path) {
            return;
        }

        $ran = false;
        $cleanup = function () use ($requestId, $path, &$ran) {
            if ($ran) {
                return;
            }
            $ran = true;

            try {
                Storage::disk('public')->delete($path);
                InventoryChangeRequest::whereKey($requestId)->update(['pending_image_path' => null]);
            } catch (Throwable $e) {
                Log::warning('Failed to delete rejected inventory request image', [
                    'request_id' => $requestId,
                    'path' => $path,
                    'exception' => $e->getMessage(),
                ]);
            }
        };

        DB::afterCommit($cleanup);

        if (app()->runningUnitTests() && DB::transactionLevel() > 0) {
            $cleanup();
        }
    }

    private function duplicateItemException(): InventoryChangeRequestException
    {
        return new InventoryChangeRequestException('An active item with this name already exists in the selected category.');
    }
}
