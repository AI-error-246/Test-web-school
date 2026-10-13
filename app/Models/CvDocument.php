<?php

namespace App\Models;

use Database\Factories\CvDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CvDocument extends Model
{
    /** @use HasFactory<CvDocumentFactory> */
    use HasFactory;

    public const CREATED_AT = 'uploaded_at';

    public const UPDATED_AT = null;

    protected $fillable = ['candidate_id', 'display_name', 'disk', 'storage_key', 'original_filename', 'mime_type', 'size_bytes', 'uploaded_at', 'archived_at', 'scan_status', 'scan_sha256', 'scan_engine', 'scanned_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['uploaded_at' => 'immutable_datetime', 'archived_at' => 'immutable_datetime', 'scanned_at' => 'immutable_datetime', 'size_bytes' => 'integer'];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'candidate_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class, 'cv_id');
    }
}
