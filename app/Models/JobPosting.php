<?php

namespace App\Models;

use Database\Factories\JobPostingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobPosting extends Model
{
    /** @use HasFactory<JobPostingFactory> */
    use HasFactory;

    protected $fillable = ['company_id', 'category_id', 'location_id', 'title', 'description', 'requirements', 'benefits', 'work_mode', 'allowance_min', 'allowance_max', 'currency', 'deadline_at', 'publication_state', 'moderation_state', 'published_at', 'version'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['deadline_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime', 'allowance_min' => 'decimal:2', 'allowance_max' => 'decimal:2', 'version' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(JobCategory::class, 'category_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class, 'job_id');
    }

    public function moderationActions(): HasMany
    {
        return $this->hasMany(ModerationAction::class, 'job_id');
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->whereIn('publication_state', ['OPEN', 'CLOSED'])
            ->where('moderation_state', 'APPROVED')
            ->whereHas('company', fn (Builder $company): Builder => $company
                ->where('visibility', 'VISIBLE')
                ->whereHas('owner', fn (Builder $owner): Builder => $owner->where('status', 'ACTIVE')));
    }

    public function scopeAccepting(Builder $query): Builder
    {
        return $query->publiclyVisible()->where('publication_state', 'OPEN')->where('deadline_at', '>', now());
    }
}
