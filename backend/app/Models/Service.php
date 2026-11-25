<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'name',
        'description',
        'duration_min',
        'buffer_min',
        'price_cents',
        'deposit_cents',
        'is_active',
        'booking_lead_time_minutes',
    ];

    protected function casts(): array
    {
        return [
            'duration_min' => 'integer',
            'buffer_min' => 'integer',
            'price_cents' => 'integer',
            'deposit_cents' => 'integer',
            'is_active' => 'boolean',
            'booking_lead_time_minutes' => 'integer',
        ];
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(Staff::class)->withTimestamps();
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
