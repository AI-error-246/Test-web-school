<?php

namespace App\Models;

use Database\Factories\JobApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobApplication extends Model
{
    /** @use HasFactory<JobApplicationFactory> */
    use HasFactory;

    protected $table = 'applications';

    public const CREATED_AT = 'applied_at';

    protected $fillable = ['job_id', 'candidate_id', 'cv_id', 'name_snapshot', 'email_snapshot', 'phone_snapshot', 'university_snapshot', 'major_snapshot', 'job_title_snapshot', 'company_name_snapshot', 'status', 'version', 'applied_at', 'updated_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['applied_at' => 'immutable_datetime', 'version' => 'integer'];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(JobPosting::class, 'job_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'candidate_id');
    }

    public function cv(): BelongsTo
    {
        return $this->belongsTo(CvDocument::class, 'cv_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(ApplicationStatusHistory::class, 'application_id');
    }
}
