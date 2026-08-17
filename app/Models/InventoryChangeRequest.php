<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryChangeRequest extends Model
{
    public const TYPE_CREATE_ITEM = 'create_item';

    public const TYPE_ADD = 'add';

    public const TYPE_SUBTRACT = 'subtract';

    public const TYPE_SET = 'set';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const REQUEST_TYPES = [
        self::TYPE_CREATE_ITEM,
        self::TYPE_ADD,
        self::TYPE_SUBTRACT,
        self::TYPE_SET,
    ];

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'request_type',
        'inventory_item_id',
        'request_payload',
        'pending_image_path',
        'quantity',
        'stock_at_request',
        'status',
        'reason',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'quantity' => 'integer',
        'stock_at_request' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function item()
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function movements()
    {
        return $this->hasMany(InventoryStockMovement::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCreateItem(): bool
    {
        return $this->request_type === self::TYPE_CREATE_ITEM;
    }

    public function payloadValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->request_payload, $key, $default);
    }

    public function displayItemName(): string
    {
        return $this->item?->item_name
            ?? (string) $this->payloadValue('item_name', 'New inventory item');
    }
}
