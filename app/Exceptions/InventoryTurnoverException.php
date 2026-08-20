<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;

class InventoryTurnoverException extends RuntimeException
{
    public function render(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 422);
        }

        return back()->with('error', $this->getMessage());
    }
}
