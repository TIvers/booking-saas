<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'name',
        'address',
        'timezone',
    ];

    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    public function workSchedules(): HasMany
    {
        return $this->hasMany(LocationWorkSchedule::class);
    }

    public function scheduleClosures(): HasMany
    {
        return $this->hasMany(LocationScheduleClosure::class);
    }
}
