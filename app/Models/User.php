<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $rememberTokenName = null;

    protected $fillable = ['id', 'name', 'email', 'role', 'status', 'is_demo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_demo' => 'boolean', 'auth_version' => 'integer'];
    }

    public function candidateProfile(): HasOne
    {
        return $this->hasOne(CandidateProfile::class, 'user_id');
    }

    public function company(): HasOne
    {
        return $this->hasOne(Company::class, 'owner_user_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class, 'candidate_id');
    }
}
