<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'timezone',
        'currency',
        'settings',
        'allow_customer_rescheduling',
        'max_customer_reschedules',
        'booking_lead_time_minutes',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'allow_customer_rescheduling' => 'boolean',
            'max_customer_reschedules' => 'integer',
            'booking_lead_time_minutes' => 'integer',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
