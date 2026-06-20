<?php

namespace App\Services;

use App\Models\PromoCode;
use Exception;

class PromoCodeService
{
    /**
     * Fetch, lock, validate and calculate the discount for a given promo code.
     * 
     * @param string $code
     * @param float $subtotal
     * @return array
     * @throws Exception
     */
    public static function apply(string $code, float $subtotal): array
    {
        $promo = PromoCode::where('code', $code)->lockForUpdate()->first();
        
        if (!$promo || !$promo->isValid()) {
            throw new Exception('The promo code is invalid or expired.');
        }

        $discount = $promo->discount_type === 'percent'
            ? round($subtotal * ($promo->discount_value / 100), 2)
            : min($subtotal, (float)$promo->discount_value);

        return [
            'type' => 'promo',
            'amount' => $discount,
            'model' => $promo,
        ];
    }
}
