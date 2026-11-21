<?php

namespace App\Domain\Shared;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/** Append-only record of sensitive actions: who, when, what, why. */
final class Audit
{
    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public static function log(
        string $action,
        ?Model $subject = null,
        ?array $old = null,
        ?array $new = null,
        ?string $reason = null,
        ?int $userId = null,
        ?int $storeId = null,
    ): AuditLog {
        $user = Auth::user();

        return AuditLog::create([
            'store_id' => $storeId ?? $user?->store_id,
            'user_id' => $userId ?? $user?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'reason' => $reason,
            'ip' => request()?->ip(),
            'created_at' => now(),
        ]);
    }
}
