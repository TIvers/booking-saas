<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'booking_id',
        'provider',
        'provider_payment_id',
        'status',
        'amount_cents',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount_cents' => 'integer',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
