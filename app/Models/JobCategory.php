<?php

namespace App\Models;

use Database\Factories\JobCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobCategory extends Model
{
    /** @use HasFactory<JobCategoryFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['name'];

    public function jobs(): HasMany
    {
        return $this->hasMany(JobPosting::class, 'category_id');
    }
}
