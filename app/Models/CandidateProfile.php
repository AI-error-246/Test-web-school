<?php

namespace App\Models;

use Database\Factories\CandidateProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CandidateProfile extends Model
{
    /** @use HasFactory<CandidateProfileFactory> */
    use HasFactory;

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    protected $fillable = ['user_id', 'full_name', 'phone', 'university', 'major', 'study_year', 'introduction', 'skills_text', 'avatar_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['study_year' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function cvs(): HasMany
    {
        return $this->hasMany(CvDocument::class, 'candidate_id', 'user_id');
    }
}
