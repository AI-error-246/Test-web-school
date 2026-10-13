<?php

namespace App\Models;

use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['code', 'name'];

    public function jobs(): HasMany
    {
        return $this->hasMany(JobPosting::class, 'location_id');
    }
}
