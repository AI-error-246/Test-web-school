<?php

namespace App\Models;

use Database\Factories\ModerationActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModerationAction extends Model
{
    /** @use HasFactory<ModerationActionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['admin_id', 'job_id', 'company_id', 'action', 'from_state', 'to_state', 'reason', 'reviewed_version', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['reviewed_version' => 'integer'];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class, 'job_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
