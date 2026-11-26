<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One backup attempt: where the file went and whether it passed verification. */
#[Fillable([
    'store_id', 'kind', 'file_name', 'local_path', 'folder_path', 'size_bytes', 'sha256',
    'status', 'error', 'warning', 'manifest', 'created_by', 'verified_at',
])]
class Backup extends Model
{
    public const STATUS_VERIFIED = 'verified';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'manifest' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    /** The local copy still exists and may be downloaded. */
    public function hasLocalFile(): bool
    {
        return $this->local_path !== null && is_file($this->local_path);
    }
}
