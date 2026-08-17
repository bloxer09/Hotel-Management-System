<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

class ExpenseCategory extends Model
{
    public const UNCATEGORIZED = 'Uncategorized';

    public const DEFAULT_NAMES = [
        'Supplies',
        'Salary',
        'Maintenance',
        'Utilities',
        'Food & Beverage',
        'Laundry',
        'Transportation',
        'Marketing',
        'Miscellaneous',
        self::UNCATEGORIZED,
    ];

    protected $fillable = [
        'name',
    ];

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    public static function normalizeName(?string $name): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim((string) $name)) ?? '';

        if ($collapsed === '') {
            return '';
        }

        return mb_convert_case($collapsed, MB_CASE_TITLE, 'UTF-8');
    }

    public static function findOrCreateFromName(string $name): self
    {
        $normalized = self::normalizeName($name);

        if ($normalized === '') {
            abort(422, 'Category is required.');
        }

        $existing = self::findByNormalizedName($normalized);

        if ($existing) {
            return $existing;
        }

        try {
            return self::create(['name' => $normalized]);
        } catch (UniqueConstraintViolationException) {
            return self::findByNormalizedName($normalized) ?? self::query()
                ->where('name', $normalized)
                ->firstOrFail();
        }
    }

    public static function findByNormalizedName(string $normalized): ?self
    {
        return self::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($normalized, 'UTF-8')])
            ->first();
    }

    public static function uncategorized(): self
    {
        return self::findOrCreateFromName(self::UNCATEGORIZED);
    }

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULT_NAMES as $name) {
            self::findOrCreateFromName($name);
        }
    }
}
