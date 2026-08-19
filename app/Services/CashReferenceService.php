<?php

namespace App\Services;

use App\Support\HotelDateTime;
use Illuminate\Support\Facades\DB;

class CashReferenceService
{
    public static function nextExpenseReference(): string
    {
        return self::next('EXP', 'expenses');
    }

    public static function nextAdditionalCashReference(): string
    {
        return self::next('ADC', 'additional_cash');
    }

    private static function next(string $prefix, string $table): string
    {
        $head = strtoupper($prefix).'-'.HotelDateTime::now()->format('Ymd').'-';

        return DB::transaction(function () use ($table, $head) {
            $latest = DB::table($table)
                ->where('reference', 'like', $head.'%')
                ->lockForUpdate()
                ->orderByDesc('reference')
                ->value('reference');

            $seq = 1;
            if (is_string($latest) && preg_match('/-(\d+)$/', $latest, $matches) === 1) {
                $seq = ((int) $matches[1]) + 1;
            }

            do {
                $reference = $head.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
                $exists = DB::table($table)->where('reference', $reference)->exists();
                $seq++;
            } while ($exists);

            return $reference;
        });
    }
}
