<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TelegramMarketingSubscription extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'chat_id',
        'subscribed_at',
    ];

    protected function casts(): array
    {
        return [
            'subscribed_at' => 'immutable_datetime',
        ];
    }
}
