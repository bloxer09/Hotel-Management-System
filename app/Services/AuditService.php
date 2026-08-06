<?php

namespace App\Services;

use App\Models\AuditLog;

class AuditService
{
    public static function log(
        int|null $userId,
        string $action,
        string $module,
        ?int $recordId = null,
        mixed $oldValue = null,
        mixed $newValue = null,
        ?string $reason = null
    ): void {
        AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'module' => $module,
            'record_id' => $recordId,
            'old_value' => is_scalar($oldValue) ? $oldValue : json_encode($oldValue),
            'new_value' => is_scalar($newValue) ? $newValue : json_encode($newValue),
            'reason' => $reason,
            'ip_address' => request()->ip(),
        ]);
    }
}
