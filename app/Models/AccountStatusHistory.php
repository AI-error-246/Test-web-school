<?php

namespace App\Models;

use Database\Factories\AccountStatusHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountStatusHistory extends Model
{
    /** @use HasFactory<AccountStatusHistoryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['target_user_id', 'admin_id', 'from_status', 'to_status', 'reason', 'created_at'];

    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
