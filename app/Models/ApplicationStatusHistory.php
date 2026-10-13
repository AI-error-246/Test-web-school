<?php

namespace App\Models;

use Database\Factories\ApplicationStatusHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationStatusHistory extends Model
{
    /** @use HasFactory<ApplicationStatusHistoryFactory> */
    use HasFactory;

    public const CREATED_AT = 'changed_at';

    public const UPDATED_AT = null;

    protected $fillable = ['application_id', 'changed_by', 'from_status', 'to_status', 'changed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['changed_at' => 'immutable_datetime'];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(JobApplication::class, 'application_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
