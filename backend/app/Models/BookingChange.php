<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingChange extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'booking_id',
        'actor_user_id',
        'action',
        'from_starts_at',
        'to_starts_at',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'from_starts_at' => 'immutable_datetime',
            'to_starts_at' => 'immutable_datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
