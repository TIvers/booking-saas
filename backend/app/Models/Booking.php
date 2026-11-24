<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'staff_id',
        'service_id',
        'customer_id',
        'starts_at',
        'ends_at',
        'status',
        'expires_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            BookingStatus::Pending->value,
            BookingStatus::Confirmed->value,
        ]);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }
}
