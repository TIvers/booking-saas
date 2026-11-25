<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramSubscription extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'user_id',
        'chat_id',
        'enabled',
        'remind_24h',
        'remind_2h',
        'connected_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'remind_24h' => 'boolean',
            'remind_2h' => 'boolean',
            'connected_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
