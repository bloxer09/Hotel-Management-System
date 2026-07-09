<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    /**
     * Get a setting by key.
     */
    public static function getValue(string $key, $default = null)
    {
        return Cache::rememberForever("settings.{$key}", function () use ($key, $default) {
            $setting = self::where('key', $key)->first();
            return $setting ? $setting->value : $default;
        });
    }

    /**
     * Set a setting value.
     */
    public static function setValue(string $key, $value)
    {
        Cache::forget("settings.{$key}");
        return self::updateOrCreate(
            ['key' => $key],
            ['value' => (string) $value]
        );
    }
}
