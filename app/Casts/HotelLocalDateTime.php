<?php

namespace App\Casts;

use App\Support\HotelDateTime;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stay timestamps are Asia/Manila naive wall-clock values. Serialize them
 * as "Y-m-d H:i:s" without a trailing Z so Inertia/JSON does not declare
 * them as UTC.
 */
class HotelLocalDateTime implements CastsAttributes, SerializesCastableAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return HotelDateTime::parseLocal(is_string($value) ? $value : (string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return HotelDateTime::toDatabase($value instanceof Carbon ? $value : (string) $value);
    }

    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return HotelDateTime::toDatabase($value instanceof Carbon ? $value : (string) $value);
    }
}
